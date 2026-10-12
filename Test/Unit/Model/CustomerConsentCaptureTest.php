<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\CustomerConsentCapture;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use PHPUnit\Framework\TestCase;

class CustomerConsentCaptureTest extends TestCase
{
    use DbTestTrait;

    private function capture(bool $consent = true, bool $enabled = true): CustomerConsentCapture
    {
        $consentApi = $this->createMock(ConsentInterface::class);
        $consentApi->method('getCurrent')->with('cart_reminder')->willReturn($consent ? self::consent('cid-1') : null);
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturn('browser-hash');
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        return new CustomerConsentCapture($this->resource(), $consentApi, $this->guard(), $browser, $config);
    }

    public function testStoresConsentOnTheCustomersCartOnly(): void
    {
        $this->assertTrue($this->capture()->execute(42, 15));
        $update = $this->updates[0];
        // never touches the email - it comes from the account
        $this->assertSame(['ck_abandoned_consent_id' => 'cid-1', 'ck_abandoned_browser_hash' => 'browser-hash'], $update['data']);
        $this->assertSame(42, $update['where']['entity_id = ?']);
        $this->assertSame(15, $update['where']['customer_id = ?']);
        $this->assertSame(1, $update['where']['is_active = ?']);
        $this->assertContains("VALID('cid-1')", self::conditions($update['where']), 'consent re-checked inside the UPDATE');
    }

    public function testOnlyWhenConsentChangesSoTheDelayIsNotRestarted(): void
    {
        $this->capture()->execute(42, 15);
        $changeOnly = implode(' ', self::conditions($this->updates[0]['where']));
        $this->assertStringContainsString('ck_abandoned_consent_id IS NULL', $changeOnly);
        $this->assertStringContainsString("ck_abandoned_consent_id <> 'cid-1'", $changeOnly);
        $this->assertStringContainsString("ck_abandoned_browser_hash <> 'browser-hash'", $changeOnly);
    }

    /** @dataProvider refusedCases */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedCases')]
    public function testRefused(int $quoteId, int $customerId, bool $consent, bool $enabled): void
    {
        $this->assertFalse($this->capture($consent, $enabled)->execute($quoteId, $customerId));
        $this->assertSame([], $this->updates);
    }

    public static function refusedCases(): array
    {
        return [
            'no cart' => [0, 15, true, true],
            'not logged in' => [42, 0, true, true],
            'no consent / withdrawn' => [42, 15, false, true],
            'module disabled' => [42, 15, true, false],
        ];
    }
}
