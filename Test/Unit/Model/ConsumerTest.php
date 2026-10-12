<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\Consumer;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\AbandonedCart\Model\RecipientHistory;
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
        'ck_abandoned_reminded_at' => null, 'ck_abandoned_consent_id' => 'cid-1',
    ];

    private array $logs = [];
    private array $mail = [];
    private bool $sendFails = false;
    private Emulation $emulation;
    private RecipientHistory $recipients;
    private bool $recentRecipient = false;
    private bool $recipientClaim = true;
    private bool $recipientClaimFails = false;
    private bool $quoteModel = true;

    private function consumer(array|false $row = self::ROW, bool $enabled = true, bool $withdrawn = false): Consumer
    {
        $this->fetchRowResult = $row;
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getTemplate')->willReturn('tpl');
        $config->method('getIdentity')->willReturn('custom1');
        $consent = $this->createStub(ConsentInterface::class);
        $consent->method('isValid')->willReturnCallback(fn ($id) => $id === 'cid-1' && !$withdrawn);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function ($msg) { $this->logs[] = $msg; });
        $repo = $this->createStub(CartRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(fn () => $this->createStub($this->quoteModel ? Quote::class : CartInterface::class));
        $data = $this->createStub(ReminderData::class);
        $data->method('build')->willReturn(['customer_name' => 'Jane', 'items_html' => '']);
        $this->emulation = $this->createMock(Emulation::class);
        $this->recipients = $this->createMock(RecipientHistory::class);
        $this->recipients->method('isRecent')->willReturnCallback(fn () => $this->recentRecipient);
        $this->recipients->method('claim')->willReturnCallback(function () {
            if ($this->recipientClaimFails) {
                throw new \RuntimeException('DB down');
            }
            return $this->recipientClaim;
        });

        $transport = $this->createStub(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function () {
            if ($this->sendFails) {
                throw new \RuntimeException('SMTP down');
            }
        });
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnCallback(function (...$args) use ($builder, $method) {
                $this->mail[$method] = $args;
                return $builder;
            });
        }
        $builder->method('getTransport')->willReturn($transport);
        return new Consumer($this->resource(), $repo, $data, $builder, $this->emulation, $config, $consent, $this->guard(), $this->recipients, $logger);
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
            'no email' => [['customer_email' => null] + self::ROW, true, false, 'no email'],
            'consent cleared after queuing' => [['ck_abandoned_consent_id' => null] + self::ROW, true, false, 'no consent'],
            'already reminded' => [['ck_abandoned_reminded_at' => '2026-10-10 09:00:00'] + self::ROW, true, false, 'already reminded'],
            'consent withdrawn / expired / old revision' => [self::ROW, true, true, 'consent withdrawn / expired'],
            'module disabled' => [self::ROW, false, false, 'disabled'],
        ];
    }

    public function testAddressRemindedRecentlyIsSkippedEvenIfThatCartIsGone(): void
    {
        // regression: guest cart deleted by Magento's login merge - the history lives in its own table
        $consumer = $this->consumer();
        $this->recentRecipient = true;
        $consumer->process('42');
        $this->assertSame([], $this->updates);
        $this->assertSame([], $this->mail);
        $this->assertSame(['CommerceKings_AbandonedCart: cart 42 skipped (address reminded recently)'], $this->logs);
    }

    public function testParallelConsumerWonTheAddressReleasesTheCart(): void
    {
        $consumer = $this->consumer();
        $this->recipientClaim = false;
        $this->recipients->expects($this->never())->method('release');
        $consumer->process('42');
        $this->assertSame([], $this->mail);
        $this->assertCount(2, $this->updates, 'cart claimed, then released');
        $this->assertNull($this->updates[1]['data']['ck_abandoned_reminded_at']);
        $this->assertSame(['CommerceKings_AbandonedCart: cart 42 skipped (address reminded recently)'], $this->logs);
    }

    public function testAddressClaimErrorReleasesTheCartForRetry(): void
    {
        // regression: the claim query throwing left the cart "reminded" with no email sent - never retried
        $consumer = $this->consumer();
        $this->recipientClaimFails = true;
        $this->recipients->expects($this->never())->method('release'); // not ours to release
        try {
            $consumer->process('42');
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('DB down', $e->getMessage());
        }
        $this->assertSame([], $this->mail);
        $release = end($this->updates);
        $this->assertNull($release['data']['ck_abandoned_reminded_at']);
        $this->assertNull($release['data']['ck_abandoned_queued_at']);
        $this->assertSame(42, $release['where']['entity_id = ?']);
    }

    public function testBuildErrorAfterAddressClaimReleasesBoth(): void
    {
        $consumer = $this->consumer();
        $this->sendFails = true;
        $this->recipients->expects($this->once())->method('release')->with('a@test.com');
        $this->expectException(\RuntimeException::class);
        $consumer->process('42');
    }

    public function testUnsupportedCartRepresentationReleasesBothClaimsWithoutSending(): void
    {
        $consumer = $this->consumer();
        $this->quoteModel = false;
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');
        $this->recipients->expects($this->once())->method('release')->with('a@test.com');
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

    public function testAddressIsClaimedBeforeSending(): void
    {
        $consumer = $this->consumer();
        $this->recipients->expects($this->once())->method('claim')->with('a@test.com')->willReturn(true);
        $consumer->process('42');
        $this->assertSame(['a@test.com', 'Jane'], $this->mail['addTo']);
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
        $this->assertSame('a@test.com', $claim['where']['customer_email = ?']);
        $this->assertSame('cid-1', $claim['where']['ck_abandoned_consent_id = ?']);
        $this->assertContains('VALID(ck_abandoned_consent_id)', self::conditions($claim['where']));

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
        $this->recipients->expects($this->never())->method('claim');
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

    public function testSendErrorReleasesBothClaimsAndRethrows(): void
    {
        $consumer = $this->consumer();
        $this->sendFails = true;
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');
        $this->recipients->expects($this->once())->method('release')->with('a@test.com');
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
