<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const PREFIX = 'commercekings_abandoned_cart/general/';

    /** The consent purpose of the reminder (CommerceKings_Consent) - declared in etc/di.xml. */
    public const PURPOSE = 'cart_reminder';

    /** The restore link in a reminder works this many days after it was sent. */
    public const RESTORE_LINK_DAYS = 7;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getDelayMinutes(?int $storeId = null): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::PREFIX . 'delay_minutes', ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Global: the finder keeps at most this many carts queued but not yet sent; consumers that drain faster get
     * refilled more often, so throughput follows the number of consumers.
     */
    public function getMaxPending(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::PREFIX . 'max_pending'));
    }

    /** Global brake for the mail provider: reminders sent plus pending in the last hour; 0 = no limit. */
    public function getMaxPerHour(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::PREFIX . 'max_per_hour'));
    }

    public function getTemplate(int $storeId): string
    {
        return (string)$this->scopeConfig->getValue(self::PREFIX . 'template', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getIdentity(int $storeId): string
    {
        return (string)$this->scopeConfig->getValue(self::PREFIX . 'identity', ScopeInterface::SCOPE_STORE, $storeId);
    }
}
