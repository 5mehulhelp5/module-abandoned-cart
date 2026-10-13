<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;

/** Restore authorization is checked again against locked, freshly loaded carts on POST. */
class RestoreAccess
{
    public function __construct(
        private readonly LinkSignature $signature,
        private readonly BrowserIdentityInterface $browser,
        private readonly StoreManagerInterface $stores,
        private readonly QuoteLifetime $lifetime
    ) {
    }

    public function isOwner(Quote $quote, string $token): bool
    {
        $hash = (string)$quote->getData('ck_abandoned_browser_hash');
        return (int)$quote->getStoreId() === (int)$this->stores->getStore()->getId()
            && $this->signature->isValid('restore', (int)$quote->getId(), $token)
            && $hash !== '' && hash_equals($hash, $this->browser->hash());
    }

    public function isAvailable(Quote $quote): bool
    {
        $value = (string)$quote->getData('ck_abandoned_reminded_at');
        $sent = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $quote->getIsActive() && $quote->getItemsCount() && $sent
            && !$this->lifetime->isExpired((string)$quote->getUpdatedAt(), (int)$quote->getStoreId())
            && $sent->format('Y-m-d H:i:s') === $value && $sent <= $now
            && $sent->modify('+' . Config::RESTORE_LINK_DAYS . ' days') > $now;
    }
}
