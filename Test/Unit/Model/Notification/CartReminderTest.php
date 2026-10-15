<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model\Notification;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Model\Config as NotificationConfig;
use CommerceKings\Notification\Model\Mailer;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// Shared fixtures use scenario-specific expectations; other outcomes are asserted from captured data.
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
/** The cart rules plus the real CommerceKings_Notification Mailer, with Magento's transport stubbed. */
class CartReminderTest extends TestCase
{
    use DbTestTrait;

    private const ROW = [
        'is_active' => '1', 'items_count' => '2', 'customer_email' => 'a@test.com', 'store_id' => '1',
        'customer_id' => null, 'contact_id' => '7', 'captured_at' => null,
        'reminded_at' => null, 'consent_id' => 'cid-1', 'updated_at' => '2026-10-01 12:00:00',
    ];

    private array $logs = [];
    private array $mail = [];
    private bool $sendFails = false;
    private Emulation $emulation;
    private ?ReminderPolicyInterface $policy = null;
    private bool $expired = false;
    private int $sentMessages = 0;
    private bool $quoteModel = true;
    private bool $scopeMatches = true;
    private ?array $recipientSequence = null;
    private bool $oneClick = false;
    private bool $recipientClaimed = false;
    private array $releasedRecipients = [];
    private bool $headerSupport = true;
    private \Symfony\Component\Mime\Email $nativeMessage;
    private string $optoutUrl = 'https://shop.test/ckconsent/optout?c=id&t=signature';

    private function consumer(array|false $row = self::ROW, bool $enabled = true, bool $withdrawn = false): CartReminder
    {
        $this->fetchRowResult = $row;
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getTemplate')->willReturn('tpl');
        $config->method('getIdentity')->willReturn('custom1');
        $config->method('getDelayMinutes')->willReturn(180);
        $consent = $this->createStub(ConsentInterface::class);
        $consent->method('isValid')->willReturnCallback(fn ($id) => $id === 'cid-1' && !$withdrawn);
        $consent->method('get')->willReturn(self::consent('cid-1'));
        $scope = $this->createStub(QuoteScope::class);
        $scope->method('matches')->willReturnCallback(fn () => $this->scopeMatches);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function ($msg) { $this->logs[] = $msg; });
        $repo = $this->createStub(CartRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(fn () => $this->createStub($this->quoteModel ? Quote::class : CartInterface::class));
        $data = $this->createStub(ReminderData::class);
        $data->method('build')->willReturn(['customer_name' => 'Jane', 'items_html' => '', 'optout_url' => $this->optoutUrl]);
        $this->emulation = $this->createMock(Emulation::class);
        $lifetime = $this->createStub(QuoteLifetime::class);
        $lifetime->method('isExpired')->willReturnCallback(fn () => $this->expired);
        $lifetime->method('validCondition')->willReturn('NOT_EXPIRED(updated_at,1)');

        $this->nativeMessage = (new \Symfony\Component\Mime\Email())->from('shop@example.test')->to('guest@example.test')->text('Reminder');
        $message = $this->createStub(\Magento\Framework\Mail\EmailMessage::class);
        $message->method('getSymfonyMessage')->willReturn($this->nativeMessage);
        $consentConfig = $this->createStub(\CommerceKings\Consent\Model\Config::class);
        $consentConfig->method('isOneClickUnsubscribeEnabled')->willReturnCallback(fn () => $this->oneClick);
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('getMessage')->willReturnCallback(fn () => $this->headerSupport ? $message : null);
        $transport->method('sendMessage')->willReturnCallback(function () {
            if ($this->sendFails) {
                throw new \RuntimeException('SMTP down');
            }
            if ($this->oneClick) { $this->assertNotNull($this->nativeMessage->getHeaders()->get('List-Unsubscribe'), 'Apply headers before sending'); }
            $this->sentMessages++;
        });
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnCallback(function (...$args) use ($builder, $method) {
                $this->mail[$method] = $args;
                return $builder;
            });
        }
        $builder->method('getTransport')->willReturn($transport);
        $recipients = $this->createStub(ReminderEmailResolverInterface::class);
        $recipients->method('resolve')->willReturnCallback(fn () => $this->recipientSequence !== null
            ? array_shift($this->recipientSequence) : ($row ? ($row['customer_id'] === null ? ($row['contact_id'] ? 'a@test.com' : null) : $row['customer_email']) : null));
        $history = $this->createStub(CooldownInterface::class);
        $history->method('claim')->willReturnCallback(function () {
            if ($this->recipientClaimed) { return null; }
            $this->recipientClaimed = true;
            return 'claim-token';
        });
        $history->method('release')->willReturnCallback(function ($email, $token) {
            $this->releasedRecipients[] = [$email, $token];
            $this->recipientClaimed = false;
        });
        $notificationConfig = $this->createStub(NotificationConfig::class);
        $notificationConfig->method('getCooldownDays')->willReturn(7);
        $mailer = new Mailer($builder, $this->emulation, $history, $notificationConfig);
        return new CartReminder($this->resource(), $repo, $data, $mailer, $config, $consent, $this->guard(), $this->policy ?? new OncePerCart(), $logger, $scope, $lifetime, $recipients, $consentConfig, $this->carts());
    }

    /** @dataProvider skipCases */
    #[\PHPUnit\Framework\Attributes\DataProvider('skipCases')]
    public function testSkipsWithReason(array|false $row, bool $enabled, bool $withdrawn, string $reason): void
    {
        $this->consumer($row, $enabled, $withdrawn)->process('42');
        $this->assertSame([], $this->updates, 'no claim');
        $this->assertSame([], $this->mail, 'no email');
        $this->assertSame(["CommerceKings_AbandonedCart: cart 42 skipped ($reason)"], $this->logs);
    }

    public static function skipCases(): array
    {
        return [
            'cart gone' => [false, true, false, 'cart gone'],
            'ordered' => [['is_active' => '0'] + self::ROW, true, false, 'ordered / closed'],
            'empty' => [['items_count' => '0'] + self::ROW, true, false, 'empty'],
            'no email' => [['contact_id' => null] + self::ROW, true, false, 'no email'],
            'consent cleared after queuing' => [['consent_id' => null] + self::ROW, true, false, 'no consent'],
            'already reminded' => [['reminded_at' => '2026-10-10 09:00:00'] + self::ROW, true, false, 'reminder policy'],
            'consent withdrawn / expired / old revision' => [self::ROW, true, true, 'consent withdrawn / expired'],
            'module disabled' => [self::ROW, false, false, 'disabled'],
        ];
    }

    public function testConsentFromAnotherWebsiteOrPurposeCannotClaimOrSend(): void
    {
        $this->scopeMatches = false;
        $this->consumer()->process('42');
        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->mail);
        $this->assertSame(['CommerceKings_AbandonedCart: cart 42 skipped (consent purpose / website mismatch)'], $this->logs);
    }

    public function testTwoCartsUsingTheSameAddressSendOnlyOnceDuringCooldown(): void
    {
        $consumer = $this->consumer();
        $consumer->process('42');
        $consumer->process('43');
        $this->assertSame(1, $this->sentMessages);
        $this->assertNull($this->updates[2]['data']['reminded_at']);
        $this->assertNull($this->updates[2]['data']['queued_at']);
        $this->assertSame([], $this->releasedRecipients);
    }

    public function testFailedSendReleasesRecipientClaimForRetry(): void
    {
        $consumer = $this->consumer();
        $this->sendFails = true;
        try { $consumer->process('42'); $this->fail('Expected SMTP failure'); }
        catch (\RuntimeException $e) { $this->assertSame('SMTP down', $e->getMessage()); }
        $this->assertSame([['a@test.com', 'claim-token']], $this->releasedRecipients);
        $this->sendFails = false;
        $consumer->process('42');
        $this->assertSame(1, $this->sentMessages);
    }

    public function testExpiredQuoteCannotClaimOrSend(): void
    {
        $this->expired = true;
        $this->consumer()->process('42');
        $this->assertSame([], $this->updates);
        $this->assertSame(0, $this->sentMessages);
        $this->assertSame(['CommerceKings_AbandonedCart: cart 42 skipped (quote expired)'], $this->logs);
    }

    public function testActivityAfterQueuingPostponesDelivery(): void
    {
        $this->consumer(['updated_at' => gmdate('Y-m-d H:i:s')] + self::ROW)->process('42');
        $this->assertSame([], $this->updates);
        $this->assertSame(0, $this->sentMessages);
        $this->assertSame(['CommerceKings_AbandonedCart: cart 42 skipped (cart recently updated)'], $this->logs);
    }

    public function testAlternativePolicyControlsCheckAndAtomicClaim(): void
    {
        $this->policy = $this->createMock(ReminderPolicyInterface::class);
        $last = '2026-10-01 12:00:00';
        $this->policy->expects($this->once())->method('canSend')->with($this->callback(fn (ReminderState $state) =>
            $state->quoteId === 42 && $state->storeId === 1 && $state->lastRemindedAt === $last
            && $state->getAttribute('updated_at') === self::ROW['updated_at']))->willReturn(true);
        $this->policy->expects($this->once())->method('dueCondition')->with($this->callback(fn (ReminderQuery $query) =>
            $query->storeId === 1 && $query->column('quote_id') === 'c.quote_id'))->willReturn('CUSTOM_POLICY');
        $this->consumer(['reminded_at' => $last] + self::ROW)->process('42');
        $this->assertSame(1, $this->sentMessages);
        $this->assertContains('CUSTOM_POLICY', self::conditions($this->updates[0]['where']));
        $this->assertContains("c.reminded_at = '$last'", self::conditions($this->updates[0]['where']));
    }

    public function testFailedDeliveryRestoresAlternativePolicyState(): void
    {
        $this->policy = $this->createStub(ReminderPolicyInterface::class);
        $this->policy->method('canSend')->willReturn(true);
        $this->policy->method('dueCondition')->willReturn('CUSTOM_POLICY');
        $this->sendFails = true;
        $last = '2026-10-01 12:00:00';
        try {
            $this->consumer(['reminded_at' => $last] + self::ROW)->process('42');
            $this->fail('transport should fail');
        } catch (\RuntimeException $error) {
            $this->assertSame('SMTP down', $error->getMessage());
        }
        $this->assertSame($last, $this->updates[1]['data']['reminded_at']);
        $this->assertNull($this->updates[1]['data']['queued_at']);
    }

    public function testUnsupportedCartRepresentationReleasesCartClaimWithoutSending(): void
    {
        $consumer = $this->consumer();
        $this->quoteModel = false;
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');
        try {
            $consumer->process('42');
            $this->fail('unsupported cart must not be sent');
        } catch (\UnexpectedValueException $e) {
            $this->assertSame('Cart repository must return a Magento quote for a reminder.', $e->getMessage());
        }
        $this->assertSame([], $this->mail);
        $this->assertCount(2, $this->updates);
        $this->assertNull($this->updates[1]['data']['reminded_at']);
        $this->assertNull($this->updates[1]['data']['queued_at']);
        $this->assertSame('ck_abandoned_cart', $this->updates[1]['table']);
        $this->assertSame(42, $this->updates[1]['where']['quote_id = ?']);
    }

    public function testClaimsThenSends(): void
    {
        $consumer = $this->consumer();
        $this->emulation->expects($this->once())->method('startEnvironmentEmulation')->with(1, 'frontend', true);
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');
        $consumer->process('42');

        $claim = $this->updates[0];
        $this->assertSame(self::joined(), $claim['table']);
        $this->assertSame(['c.reminded_at'], array_keys($claim['data']), 'Magento\'s quote row is not written');
        $this->assertSame('NOW()', (string)$claim['data']['c.reminded_at']);
        $this->assertSame(42, $claim['where']['c.quote_id = ?']);
        $this->assertContains('c.reminded_at IS NULL', self::conditions($claim['where']));
        // Codex finding 1: the claim repeats the cart and consent checks in the same statement
        $this->assertSame(1, $claim['where']['q.is_active = ?']);
        $this->assertContains('q.items_count > 0', self::conditions($claim['where']));
        $this->assertSame('7', $claim['where']['c.contact_id <=> ?']);
        $this->assertSame('cid-1', $claim['where']['c.consent_id = ?']);
        $this->assertContains('VALID(c.consent_id)', self::conditions($claim['where']));
        $this->assertContains('NOT_EXPIRED(updated_at,1)', self::conditions($claim['where']));
        $this->assertContains('q.updated_at <= NOW() - INTERVAL 180 MINUTE', self::conditions($claim['where']));
        $this->assertContains('(c.captured_at IS NULL OR c.captured_at <= NOW() - INTERVAL 180 MINUTE)', self::conditions($claim['where']));

        $this->assertSame(['tpl'], $this->mail['setTemplateIdentifier']);
        $this->assertSame(['custom1', 1], $this->mail['setFromByScope']);
        $this->assertSame(['a@test.com', 'Jane'], $this->mail['addTo']);
        $this->assertSame(['CommerceKings_AbandonedCart: reminder sent for cart 42'], $this->logs);
    }

    public function testCodexFinding1WithdrawalBetweenCheckAndClaimSendsNothing(): void
    {
        // the checks above passed, then the visitor withdrew: the claim's own consent check fails, 0 rows
        $consumer = $this->consumer();
        $this->updateResults = [0];
        $consumer->process('42');
        $this->assertSame([], $this->mail);
        $this->assertCount(1, $this->updates, 'nothing to release');
    }

    public function testDuplicateDeliveryLosesTheClaimAndSendsNothing(): void
    {
        $consumer = $this->consumer();
        $this->updateResults = [0];
        $consumer->process('42');
        $this->assertSame([], $this->mail);
    }

    public function testRecentGuestEmailChangePostponesDelivery(): void
    {
        $this->consumer(['captured_at' => gmdate('Y-m-d H:i:s')] + self::ROW)->process('42');
        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->mail);
    }

    public function testRecipientChangeAfterClaimReleasesItWithoutSending(): void
    {
        $this->recipientSequence = ['a@test.com', 'changed@test.com'];
        $this->consumer()->process('42');
        $this->assertSame([], $this->mail);
        $this->assertCount(2, $this->updates);
        $this->assertNull($this->updates[1]['data']['reminded_at']);
        $this->assertNull($this->updates[1]['data']['queued_at']);
    }

    public function testCustomerUsesMagentoEmailAndIgnoresOldGuestCapture(): void
    {
        $this->consumer(['customer_id' => 7, 'customer_email' => 'account@test.com', 'contact_id' => '7',
            'captured_at' => '2026-10-01 12:00:00'] + self::ROW)->process('42');
        $this->assertSame(['account@test.com', 'Jane'], $this->mail['addTo']);
    }

    public function testRecentCaptureChangePostponesCustomersToo(): void
    {
        // a customer's consent change stamps captured_at, as a guest's email change does
        $this->consumer(['customer_id' => 7, 'customer_email' => 'account@test.com',
            'captured_at' => gmdate('Y-m-d H:i:s')] + self::ROW)->process('42');
        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->mail);
    }

    public function testSendErrorReleasesCartClaimAndRethrows(): void
    {
        $consumer = $this->consumer();
        $this->sendFails = true;
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');
        try {
            $consumer->process('42');
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMTP down', $e->getMessage());
        }
        $release = $this->updates[1];
        $this->assertNull($release['data']['reminded_at']);
        $this->assertNull($release['data']['queued_at']);
        $this->assertSame(['reminded_at', 'queued_at'], array_keys($release['data']), 'only the cart state is released');
    }

    public function testOneClickHeadersAreOptionalAndDoNotReplaceTemplateVariables(): void
    {
        $consumer = $this->consumer();
        $consumer->process('42');
        $this->assertNull($this->nativeMessage->getHeaders()->get('List-Unsubscribe'));
        $originalVars = $this->mail['setTemplateVars'];
        $this->nativeMessage->getHeaders()->addTextHeader('List-Unsubscribe', '<https://old.test/unsubscribe>');
        $this->oneClick = true;
        $this->recipientClaimed = false; // The independent second delivery is outside its cooldown.
        $consumer->process('43');
        $this->assertSame('<' . $this->optoutUrl . '>', $this->nativeMessage->getHeaders()->getHeaderBody('List-Unsubscribe'));
        $this->assertSame('List-Unsubscribe=One-Click', $this->nativeMessage->getHeaders()->getHeaderBody('List-Unsubscribe-Post'));
        $this->assertSame(1, substr_count($this->nativeMessage->toString(), 'List-Unsubscribe:'));
        $this->assertSame($originalVars, $this->mail['setTemplateVars']);
        $this->assertSame(2, $this->sentMessages);
    }

    public function testInsecureOptoutCannotBeAdvertisedAsOneClick(): void
    {
        $this->oneClick = true;
        $this->optoutUrl = 'http://shop.test/ckconsent/optout';
        try {
            $this->consumer()->process('42');
            $this->fail('Expected HTTPS requirement');
        } catch (\UnexpectedValueException $error) {
            $this->assertStringContainsString('HTTPS', $error->getMessage());
        }
        $this->assertSame(0, $this->sentMessages);
        $this->assertNull($this->updates[1]['data']['reminded_at']);
    }

    public function testUnsupportedOneClickMessageDoesNotSendAndReleasesTheClaim(): void
    {
        $this->oneClick = true;
        $this->headerSupport = false;
        try {
            $this->consumer()->process('42');
            $this->fail('Expected unsupported message');
        } catch (\UnexpectedValueException $error) {
            $this->assertStringContainsString('does not support one-click', $error->getMessage());
        }
        $this->assertSame(0, $this->sentMessages);
        $this->assertCount(2, $this->updates);
        $this->assertNull($this->updates[1]['data']['reminded_at']);
    }

    public function testPostSendCleanupFailureCannotReleaseTheClaimForAnotherEmail(): void
    {
        $consumer = $this->consumer();
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation')
            ->willThrowException(new \RuntimeException('emulation cleanup failed'));
        try {
            $consumer->process('42');
            $this->fail('Expected cleanup failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('emulation cleanup failed', $error->getMessage());
        }
        $this->assertSame(1, $this->sentMessages);
        $this->assertCount(1, $this->updates, 'Keep the existing reminder claim after successful SMTP handoff');
        $this->assertSame([], $this->releasedRecipients, 'Preserve recipient suppression after SMTP handoff');
    }
}
