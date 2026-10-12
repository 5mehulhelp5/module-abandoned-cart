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

    /** One reminder per email address within this many days, across all carts (anti-flooding). */
    public const RECIPIENT_COOLDOWN_DAYS = 7;

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

    public function getDelayMinutes(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::PREFIX . 'delay_minutes'));
    }

    public function getMaxAgeDays(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::PREFIX . 'max_age_days'));
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
