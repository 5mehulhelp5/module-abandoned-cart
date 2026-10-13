<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class QuoteLifetimeTest extends TestCase
{
    private function lifetime(): QuoteLifetime
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(function ($path, $scope, $id) {
            $this->assertSame('checkout/cart/delete_quote_after', $path);
            return $scope === 'websites' ? 14 : ([1 => 30, 2 => 2, 3 => 0][$id] ?? -1);
        });
        return new QuoteLifetime($config);
    }

    public function testReadsTheSelectedStoreAndAdminScopeDynamically(): void
    {
        $lifetime = $this->lifetime();
        $this->assertSame(30, $lifetime->getDays(1));
        $this->assertSame(2, $lifetime->getDays(2));
        $this->assertSame(14, $lifetime->getScopeDays('websites', 1));
        $this->assertSame(0, $lifetime->getDays(3));
        $this->assertSame(0, $lifetime->getDays(4));
    }

    public function testExpiryDependsOnTheQuoteUpdateAndItsOwnStoresLifetime(): void
    {
        $updated = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
        $lifetime = $this->lifetime();
        $this->assertFalse($lifetime->isExpired($updated, 1));
        $this->assertTrue($lifetime->isExpired($updated, 2));
        $this->assertTrue($lifetime->isExpired(gmdate('Y-m-d H:i:s'), 3));
        $this->assertTrue($lifetime->isExpired('invalid', 1));
        $this->assertTrue($lifetime->isExpired('2026-02-31 00:00:00', 1));
    }

    public function testAtomicSqlUsesTheSameStoreLifetimeAndRejectsZero(): void
    {
        $lifetime = $this->lifetime();
        $this->assertSame('q.updated_at > NOW() - INTERVAL 30 DAY', $lifetime->validCondition('q.updated_at', 1));
        $this->assertSame('updated_at > NOW() - INTERVAL 2 DAY', $lifetime->validCondition('updated_at', 2));
        $this->assertSame('0 = 1', $lifetime->validCondition('updated_at', 3));
    }
}
