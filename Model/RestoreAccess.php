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
        private readonly QuoteLifetime $lifetime,
        private readonly CartTable $carts
    ) {
    }

    /** Reject forged links before the controller loads any quote. */
    public function isSigned(int $quoteId, string $token): bool
    {
        return $this->signature->isValid('restore', $quoteId, $token);
    }

    public function isOwner(Quote $quote, string $token): bool
    {
        $hash = (string)($this->carts->load((int)$quote->getId())['browser_hash'] ?? '');
        return (int)$quote->getStoreId() === (int)$this->stores->getStore()->getId()
            && $this->isSigned((int)$quote->getId(), $token)
            && $hash !== '' && hash_equals($hash, $this->browser->hash());
    }

    public function isAvailable(Quote $quote): bool
    {
        $value = (string)($this->carts->load((int)$quote->getId())['reminded_at'] ?? '');
        $sent = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $quote->getIsActive() && $quote->getItemsCount() && $sent
            && !$this->lifetime->isExpired((string)$quote->getUpdatedAt(), (int)$quote->getStoreId())
            && $sent->format('Y-m-d H:i:s') === $value && $sent <= $now
            && $sent->modify('+' . Config::RESTORE_LINK_DAYS . ' days') > $now;
    }
}
