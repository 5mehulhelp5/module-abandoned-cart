<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Restore authorization is checked again against locked, freshly loaded carts on POST. The link works only for
 * the visitor who gave the subscription's permission (Notification's SubscriberInterface must be able to tell - with
 * the consent bridge: the browser the consent was given in); a withdrawal deletes the subscription, and with it
 * the restore. When the subscriber cannot be identified, reminders link to the cart page instead.
 */
class RestoreAccess
{
    public function __construct(
        private readonly LinkSignature $signature,
        private readonly SubscriberInterface $subscriber,
        private readonly StoreManagerInterface $stores,
        private readonly QuoteLifetime $lifetime,
        private readonly CartTable $carts,
        private readonly SubscriptionRepositoryInterface $subscriptions
    ) {
    }

    /** Reject forged links before the controller loads any quote. */
    public function isSigned(int $quoteId, string $token): bool
    {
        return $this->signature->isValid('restore', $quoteId, $token);
    }

    public function isOwner(Quote $quote, string $token): bool
    {
        $subscription = $this->subscriptions->find(CartReminder::CODE, (int)$quote->getId())[0] ?? null;
        return $subscription !== null
            && (int)$quote->getStoreId() === (int)$this->stores->getStore()->getId()
            && $this->isSigned((int)$quote->getId(), $token)
            && $this->subscriber->isCurrentVisitor($subscription->permissionRef);
    }

    public function isAvailable(Quote $quote): bool
    {
        $value = (string)($this->carts->load((int)$quote->getId())['sent_at'] ?? '');
        $sent = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $quote->getIsActive() && $quote->getItemsCount() && $sent
            && !$this->lifetime->isExpired((string)$quote->getUpdatedAt(), (int)$quote->getStoreId())
            && $sent->format('Y-m-d H:i:s') === $value && $sent <= $now
            && $sent->modify('+' . Config::RESTORE_LINK_DAYS . ' days') > $now;
    }
}
