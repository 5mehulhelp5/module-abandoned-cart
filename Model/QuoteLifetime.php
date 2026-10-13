<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/** Magento's configured quote lifetime, measured from the quote's last update in UTC. */
class QuoteLifetime
{
    public const CONFIG_PATH = 'checkout/cart/delete_quote_after';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function getDays(int $storeId): int
    {
        return $this->getScopeDays(ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** Also used by the read-only admin field in its selected default / website / store scope. */
    public function getScopeDays(string $scope, int $scopeId): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::CONFIG_PATH, $scope, $scopeId));
    }

    public function isExpired(string $updatedAt, int $storeId): bool
    {
        $updated = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $updatedAt, new \DateTimeZone('UTC'));
        $days = $this->getDays($storeId);
        return !$updated || $updated->format('Y-m-d H:i:s') !== $updatedAt || $days === 0
            || $updated->getTimestamp() + $days * 86400 <= time();
    }

    /** Trusted column and store id; use in the same statement that queues or claims a reminder. */
    public function validCondition(string $updatedAtColumn, int $storeId): string
    {
        $days = $this->getDays($storeId);
        return $days > 0 ? $updatedAtColumn . ' > NOW() - INTERVAL ' . $days . ' DAY' : '0 = 1';
    }
}
