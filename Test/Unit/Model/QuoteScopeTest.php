<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use CommerceKings\Consent\Api\Data\Consent;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class QuoteScopeTest extends TestCase
{
    use DbTestTrait;

    private function scope(): QuoteScope
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return new QuoteScope($this->resource(), $stores);
    }

    public function testGuestLookupRequiresAnActiveGuestOnTheCurrentWebsite(): void
    {
        $this->assertNull($this->scope()->find(42));
        $this->assertContains(['q.entity_id = ?', 42], $this->selectWhere);
        $this->assertContains(['q.is_active = ?', 1], $this->selectWhere);
        $this->assertContains(['s.website_id = ?', 1], $this->selectWhere);
        $this->assertContains(['q.customer_id IS NULL', null], $this->selectWhere);
    }

    public function testCustomerLookupRequiresTheAuthenticatedCustomer(): void
    {
        $this->fetchRowResult = ['store_id' => 3];
        $this->assertSame(['store_id' => 3], $this->scope()->find(42, 15));
        $this->assertContains(['q.customer_id = ?', 15], $this->selectWhere);
        $this->assertContains(['s.website_id = ?', 1], $this->selectWhere);
    }

    /** @dataProvider consentScopes */
    #[\PHPUnit\Framework\Attributes\DataProvider('consentScopes')]
    public function testConsentMustMatchTheReminderPurposeAndQuoteWebsite(string $purpose, int $website, bool $expected): void
    {
        $consent = new Consent('cid', $purpose, $website, 'source', 'reference', '2026-01-01 00:00:00',
            '1', '1', '2026-01-01 00:00:00', '2027-01-01 00:00:00');
        $this->assertSame($expected, $this->scope()->matches($consent, 3));
    }

    public static function consentScopes(): array
    {
        return ['match' => ['cart_reminder', 1, true], 'other website' => ['cart_reminder', 2, false],
            'other purpose' => ['analytics', 1, false]];
    }
}
