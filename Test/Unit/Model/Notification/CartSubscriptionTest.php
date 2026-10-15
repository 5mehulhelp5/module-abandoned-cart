<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model\Notification;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\Notification\CartSubscription;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResource;
use PHPUnit\Framework\TestCase;

class CartSubscriptionTest extends TestCase
{
    use DbTestTrait;

    private array $found = [];
    private bool $enabled = true;

    private function type(): CartSubscription
    {
        // Magento's getQuoteId() is magic (__call): answer it from the masked id the resource loaded
        $loaded = null;
        $mask = $this->createStub(QuoteIdMask::class);
        $mask->method('__call')->willReturnCallback(function ($method) use (&$loaded) {
            return $method === 'getQuoteId' && $loaded === 'masked-42' ? 42 : null;
        });
        $masks = $this->createStub(QuoteIdMaskFactory::class);
        $masks->method('create')->willReturn($mask);
        $maskResource = $this->createStub(QuoteIdMaskResource::class);
        $maskResource->method('load')->willReturnCallback(function ($model, $value, $field) use (&$loaded, $maskResource) {
            $this->assertSame('masked_id', $field);
            $loaded = $value;
            return $maskResource;
        });
        $scope = $this->createStub(QuoteScope::class);
        $scope->method('find')->willReturnCallback(function ($quoteId, $customerId) {
            $this->found[] = [$quoteId, $customerId];
            return $quoteId === 42 && in_array($customerId, [0, 7], true) ? ['store_id' => '2'] : null;
        });
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($storeId) => $this->enabled && $storeId === 2);
        return new CartSubscription($masks, $maskResource, $scope, $config, $this->resource());
    }

    public function testGuestNeedsTheMaskedIdOfAnActiveGuestCartInThisWebsite(): void
    {
        $target = $this->type()->authorize('masked-42', 0);
        $this->assertSame([[42, 0]], $this->found);
        $this->assertSame(42, $target->referenceId);
        $this->assertSame(2, $target->storeId);
        $this->assertTrue($target->accepting);
        $this->assertSame('EXISTS (SELECT 1 FROM quote AS ck_q WHERE ck_q.entity_id = 42 AND ck_q.store_id = 2'
            . ' AND ck_q.is_active = 1 AND ck_q.customer_id IS NULL)', $target->condition);
        $this->assertSame('cart_reminder', $this->type()->getPurpose());
        $this->assertTrue($this->type()->isSingle(), 'a new email replaces the cart\'s subscription');
    }

    public function testCustomerNeedsTheirOwnQuoteId(): void
    {
        $target = $this->type()->authorize('42', 7);
        $this->assertSame([[42, 7]], $this->found);
        $this->assertStringContainsString('ck_q.customer_id = 7', $target->condition);
        $this->assertNull($this->type()->authorize('42', 8), 'another customer\'s cart');
    }

    /** @dataProvider refused */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testUnknownOrForeignReferencesAreRefused(string $reference, int $customerId): void
    {
        $this->assertNull($this->type()->authorize($reference, $customerId));
    }

    public static function refused(): array
    {
        return ['unknown mask' => ['masked-43', 0], 'empty' => ['', 0], 'raw quote id as guest' => ['42', 0],
            'masked id as customer' => ['masked-42', 7], 'not a number' => ['42abc', 7]];
    }

    public function testSubscriptionIsAnOrphanOnceMagentoDeletedTheCart(): void
    {
        $this->assertSame('NOT EXISTS (SELECT 1 FROM quote AS ck_q WHERE ck_q.entity_id = s.reference_id)',
            $this->type()->orphanCondition('s.reference_id'));
    }

    public function testDisabledStoreIsAuthorizedButNotAccepting(): void
    {
        $this->enabled = false;
        $target = $this->type()->authorize('masked-42', 0);
        $this->assertFalse($target->accepting, 'Notification removes the guest\'s subscription');
    }
}
