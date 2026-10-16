<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const PREFIX = 'commercekings_abandoned_cart/general/';

    /** What reminders are for (SubscriptionTypeInterface::getPurpose()); with the consent bridge the Consent purpose declared in etc/di.xml. */
    public const PURPOSE = 'cart_reminder';

    /** The checkout notice's settings (show, text), read through Notification's OptOutNoticeInterface. */
    public const NOTICE_GROUP = 'commercekings_abandoned_cart/notice';

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

    /** Global brake for the mail provider: reminders per hour across all stores; 0 = no limit. */
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
