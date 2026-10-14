<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\Consumer;
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
class ConsumerTest extends TestCase
{
    use DbTestTrait;

    private const ROW = [
        'is_active' => '1', 'items_count' => '2', 'customer_email' => 'a@test.com', 'store_id' => '1',
        'customer_id' => null, 'ck_abandoned_email' => 'a@test.com', 'ck_abandoned_email_updated_at' => null,
        'ck_abandoned_reminded_at' => null, 'ck_abandoned_consent_id' => 'cid-1', 'updated_at' => '2026-10-01 12:00:00',
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

    private function consumer(array|false $row = self::ROW, bool $enabled = true, bool $withdrawn = false): Consumer
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
        $data->method('build')->willReturn(['customer_name' => 'Jane', 'items_html' => '']);
        $this->emulation = $this->createMock(Emulation::class);
        $lifetime = $this->createStub(QuoteLifetime::class);
        $lifetime->method('isExpired')->willReturnCallback(fn () => $this->expired);
        $lifetime->method('validCondition')->willReturn('NOT_EXPIRED(updated_at,1)');

        $transport = $this->createStub(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function () {
            if ($this->sendFails) {
                throw new \RuntimeException('SMTP down');
            }
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
            ? array_shift($this->recipientSequence) : ($row ? ($row['customer_id'] === null ? $row['ck_abandoned_email'] : $row['customer_email']) : null));
        return new Consumer($this->resource(), $repo, $data, $builder, $this->emulation, $config, $consent, $this->guard(), $this->policy ?? new OncePerCart(), $logger, $scope, $lifetime, $recipients);
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
            'no email' => [['ck_abandoned_email' => null] + self::ROW, true, false, 'no email'],
            'consent cleared after queuing' => [['ck_abandoned_consent_id' => null] + self::ROW, true, false, 'no consent'],
            'already reminded' => [['ck_abandoned_reminded_at' => '2026-10-10 09:00:00'] + self::ROW, true, false, 'reminder policy'],
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

    public function testTwoCartsUsingTheSameAddressBothSend(): void
    {
        $consumer = $this->consumer();
        $consumer->process('42');
        $consumer->process('43');
        $this->assertSame(2, $this->sentMessages);
        $this->assertSame([42, 43], array_map(fn ($update) => $update['where']['entity_id = ?'], $this->updates));
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
            $query->storeId === 1 && $query->column('entity_id') === 'entity_id'))->willReturn('CUSTOM_POLICY');
        $this->consumer(['ck_abandoned_reminded_at' => $last] + self::ROW)->process('42');
        $this->assertSame(1, $this->sentMessages);
        $this->assertContains('CUSTOM_POLICY', self::conditions($this->updates[0]['where']));
        $this->assertContains("ck_abandoned_reminded_at = '$last'", self::conditions($this->updates[0]['where']));
    }

    public function testFailedDeliveryRestoresAlternativePolicyState(): void
    {
        $this->policy = $this->createStub(ReminderPolicyInterface::class);
        $this->policy->method('canSend')->willReturn(true);
        $this->policy->method('dueCondition')->willReturn('CUSTOM_POLICY');
        $this->sendFails = true;
        $last = '2026-10-01 12:00:00';
        try {
            $this->consumer(['ck_abandoned_reminded_at' => $last] + self::ROW)->process('42');
            $this->fail('transport should fail');
        } catch (\RuntimeException $error) {
            $this->assertSame('SMTP down', $error->getMessage());
        }
        $this->assertSame($last, $this->updates[1]['data']['ck_abandoned_reminded_at']);
        $this->assertNull($this->updates[1]['data']['ck_abandoned_queued_at']);
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
        $this->assertNull($this->updates[1]['data']['ck_abandoned_reminded_at']);
        $this->assertNull($this->updates[1]['data']['ck_abandoned_queued_at']);
        $this->assertSame(42, $this->updates[1]['where']['entity_id = ?']);
    }

    public function testClaimsThenSends(): void
    {
        $consumer = $this->consumer();
        $this->emulation->expects($this->once())->method('startEnvironmentEmulation')->with(1, 'frontend', true);
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');
        $consumer->process('42');

        $claim = $this->updates[0];
        $this->assertSame('NOW()', (string)$claim['data']['ck_abandoned_reminded_at']);
        $this->assertSame('updated_at', (string)$claim['data']['updated_at']);
        $this->assertSame(42, $claim['where']['entity_id = ?']);
        $this->assertContains('ck_abandoned_reminded_at IS NULL', self::conditions($claim['where']));
        // Codex finding 1: the claim repeats the cart and consent checks in the same statement
        $this->assertSame(1, $claim['where']['is_active = ?']);
        $this->assertContains('items_count > 0', self::conditions($claim['where']));
        $this->assertSame('a@test.com', $claim['where']['ck_abandoned_email <=> ?']);
        $this->assertSame('cid-1', $claim['where']['ck_abandoned_consent_id = ?']);
        $this->assertContains('VALID(ck_abandoned_consent_id)', self::conditions($claim['where']));
        $this->assertContains('NOT_EXPIRED(updated_at,1)', self::conditions($claim['where']));
        $this->assertContains('updated_at <= NOW() - INTERVAL 180 MINUTE', self::conditions($claim['where']));

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
        $this->consumer(['ck_abandoned_email_updated_at' => gmdate('Y-m-d H:i:s')] + self::ROW)->process('42');
        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->mail);
    }

    public function testRecipientChangeAfterClaimReleasesItWithoutSending(): void
    {
        $this->recipientSequence = ['a@test.com', 'changed@test.com'];
        $this->consumer()->process('42');
        $this->assertSame([], $this->mail);
        $this->assertCount(2, $this->updates);
        $this->assertNull($this->updates[1]['data']['ck_abandoned_reminded_at']);
        $this->assertNull($this->updates[1]['data']['ck_abandoned_queued_at']);
    }

    public function testCustomerUsesMagentoEmailAndIgnoresOldGuestCapture(): void
    {
        $this->consumer(['customer_id' => 7, 'customer_email' => 'account@test.com',
            'ck_abandoned_email_updated_at' => gmdate('Y-m-d H:i:s')] + self::ROW)->process('42');
        $this->assertSame(['account@test.com', 'Jane'], $this->mail['addTo']);
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
        $this->assertNull($release['data']['ck_abandoned_reminded_at']);
        $this->assertNull($release['data']['ck_abandoned_queued_at']);
        $this->assertSame('updated_at', (string)$release['data']['updated_at']);
    }
}
