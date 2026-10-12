<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\ConsentWithdrawal;
use CommerceKings\AbandonedCart\Model\GuestEmailCapture;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;
use PHPUnit\Framework\TestCase;

class GuestEmailCaptureTest extends TestCase
{
    use DbTestTrait;

    private ConsentWithdrawal $withdrawal;
    private ?string $askedPurpose = null;

    private function capture(int $quoteId = 42, bool $consent = true, bool $enabled = true): GuestEmailCapture
    {
        $mask = $this->mockWith(QuoteIdMask::class, ['load' => self::$SELF, 'getQuoteId' => $quoteId]);
        $maskFactory = $this->createMock(QuoteIdMaskFactory::class);
        $maskFactory->method('create')->willReturn($mask);
        $consentApi = $this->createMock(ConsentInterface::class);
        $consentApi->method('getCurrent')->willReturnCallback(function ($purpose) use ($consent) {
            $this->askedPurpose = $purpose;
            // CommerceKings_Consent returns only a valid consent - withdrawn / old revision = null too
            return $consent ? self::consent('cid-1') : null;
        });
        $browser = $this->createMock(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturn('browser-hash');
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $this->withdrawal = $this->createMock(ConsentWithdrawal::class);
        return new GuestEmailCapture($maskFactory, $this->resource(), $consentApi, $this->guard(), $browser, $this->withdrawal, $config);
    }

    public function testUnknownCartKey(): void
    {
        $this->assertFalse($this->capture(0)->capture('nope', 'a@test.com'));
        $this->assertSame([], $this->updates);
    }

    public function testStoresEmailWithTheConsentIdForTheCartReminderPurpose(): void
    {
        $this->assertTrue($this->capture()->capture('masked', '  a@test.com '));
        $this->assertSame('cart_reminder', $this->askedPurpose);
        $this->assertSame([
            'customer_email' => 'a@test.com',
            'ck_abandoned_consent_id' => 'cid-1',
            'ck_abandoned_browser_hash' => 'browser-hash',
        ], $this->updates[0]['data']);
        $where = $this->updates[0]['where'];
        $this->assertSame(42, $where['entity_id = ?']);
        $this->assertSame(1, $where['is_active = ?']);
        $this->assertContains('customer_id IS NULL', self::conditions($where)); // guests only
    }

    public function testCodexFinding2TheConsentIsCheckedInsideTheSameUpdate(): void
    {
        // a withdrawal stored a moment earlier makes this UPDATE change nothing - it can't be undone
        $this->capture()->capture('masked', 'a@test.com');
        $this->assertContains("VALID('cid-1')", self::conditions($this->updates[0]['where']));
    }

    /** @dataProvider noConsentCases */
    public function testWithoutConsentClearsInsteadOfStoring(bool $consent, bool $enabled): void
    {
        $capture = $this->capture(42, $consent, $enabled);
        $this->withdrawal->expects($this->once())->method('execute')->with(42);
        $this->assertFalse($capture->capture('masked', 'a@test.com'));
        $this->assertSame([], $this->updates);
    }

    public static function noConsentCases(): array
    {
        return ['no consent / withdrawn' => [false, true], 'module disabled' => [true, false]];
    }

    public function testEmptyEmailWithConsentStoresNothing(): void
    {
        $capture = $this->capture();
        $this->withdrawal->expects($this->never())->method('execute');
        $this->assertFalse($capture->capture('masked', ''));
        $this->assertSame([], $this->updates);
    }

    /** @dataProvider invalidEmails */
    public function testInvalidEmailStoresNothing(string $email): void
    {
        $this->assertFalse($this->capture()->capture('masked', $email));
        $this->assertSame([], $this->updates);
    }

    public static function invalidEmails(): array
    {
        return [['not-an-email'], ['a@'], [str_repeat('a', 250) . '@test.com']];
    }
}
