<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\CustomerConsentCapture;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use PHPUnit\Framework\TestCase;

class CustomerConsentCaptureTest extends TestCase
{
    use DbTestTrait;
    private ?array $scopedQuote = ['store_id' => 3];
    private bool $scopeMatches = true;

    private function capture(bool $consent = true, bool $enabled = true): CustomerConsentCapture
    {
        $consentApi = $this->createMock(ConsentInterface::class);
        $consentApi->method('getCurrent')->with('cart_reminder')->willReturn($consent ? self::consent('cid-1') : null);
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturn('browser-hash');
        $config = $this->createMock(Config::class);
        $config->expects($this->any())->method('isEnabled')->with(3)->willReturn($enabled);
        $scope = $this->createStub(QuoteScope::class);
        $scope->method('find')->willReturnCallback(fn () => $this->scopedQuote);
        $scope->method('matches')->willReturnCallback(fn () => $this->scopeMatches);
        return new CustomerConsentCapture($this->resource(), $consentApi, $this->guard(), $browser, $config, $scope, $this->carts());
    }

    public function testStoresConsentOnTheCustomersCartOnly(): void
    {
        $this->assertTrue($this->capture()->execute(42, 15));
        $update = $this->updates[0];
        // never touches the email - it comes from the account
        $this->assertSame(self::joined(), $update['table']);
        $this->assertSame(['c.consent_id', 'c.browser_hash', 'c.captured_at'], array_keys($update['data']));
        $this->assertSame(['cid-1', 'browser-hash'], [$update['data']['c.consent_id'], $update['data']['c.browser_hash']]);
        // a changed consent restarts the reminder delay (as touching the cart did before)
        $this->assertSame('NOW()', (string)$update['data']['c.captured_at']);
        $this->assertSame(42, $update['where']['q.entity_id = ?']);
        $this->assertSame(15, $update['where']['q.customer_id = ?']);
        $this->assertSame(1, $update['where']['q.is_active = ?']);
        $this->assertContains("VALID('cid-1')", self::conditions($update['where']), 'consent re-checked inside the UPDATE');
    }

    public function testOnlyWhenConsentChangesSoTheDelayIsNotRestarted(): void
    {
        $this->capture()->execute(42, 15);
        $changeOnly = implode(' ', self::conditions($this->updates[0]['where']));
        $this->assertStringContainsString('c.consent_id IS NULL', $changeOnly);
        $this->assertStringContainsString("c.consent_id <> 'cid-1'", $changeOnly);
        $this->assertStringContainsString("c.browser_hash <> 'browser-hash'", $changeOnly);
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

    public function testOutOfScopeOrNonOwnedCartCannotCapture(): void
    {
        $this->scopedQuote = null;
        $this->assertFalse($this->capture()->execute(42, 15));
        $this->assertSame([], $this->updates);
    }

    public function testConsentFromAnotherWebsiteOrPurposeCannotBeCaptured(): void
    {
        $this->scopeMatches = false;
        $this->assertFalse($this->capture()->execute(42, 15));
        $this->assertSame([], $this->updates);
    }

    public function testRefusedWriteIsNotAcknowledged(): void
    {
        $this->updateResults = [0];
        $this->assertFalse($this->capture()->execute(42, 15));
        $this->assertContains(['q.customer_id = ?', 15], $this->selectWhere);
        $this->assertContains(['q.store_id = ?', 3], $this->selectWhere);
        $this->assertContains(["VALID('cid-1')", null], $this->selectWhere);
    }

    public function testIdenticalStoredRetryIsAcknowledged(): void
    {
        $this->updateResults = [0];
        $this->fetchOneResult = 42;
        $this->assertTrue($this->capture()->execute(42, 15));
        $this->assertContains(['c.consent_id = ?', 'cid-1'], $this->selectWhere);
        $this->assertContains(['c.browser_hash = ?', 'browser-hash'], $this->selectWhere);
    }
}
