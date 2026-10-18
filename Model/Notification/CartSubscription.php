<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model\Notification;

use CommerceKings\AbandonedCart\Api\ReminderTypeInterface;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResource;

/**
 * Who may subscribe a cart to one reminder type (the email reminder by default; another channel's through a virtual
 * type with its "type" argument, see docs/extending.md) - CommerceKings_Notification does the address rules, consent
 * and storage; the channel, purpose, permission and "enabled" are the reminder type's.
 * Guests send the masked cart id from checkout, like Magento's guest-cart APIs; customers send their quote
 * id and must own it. The cart must be active in the current website. The same cart conditions are repeated
 * inside Notification's write, so a cart that changed after this check is not subscribed. A subscription goes
 * when Magento deletes its cart (quote cleanup), and with it the guest's address.
 *
 * @api a virtual type with its "type" argument (ReminderTypeInterface) is a stable extension point
 */
class CartSubscription implements SubscriptionTypeInterface
{
    public function __construct(
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly QuoteIdMaskResource $quoteIdMaskResource,
        private readonly QuoteScope $scope,
        private readonly ResourceConnection $resource,
        private readonly ReminderTypeInterface $type
    ) {
    }

    public function getPurpose(): string
    {
        return $this->type->getPurpose();
    }

    /** The reminder type's own permission; null (the email reminder): the shop-wide one. */
    public function getPermission(): ?SubscriptionPermissionInterface
    {
        return $this->type->getPermission();
    }

    /** The subscriber behind the type's own permission; null: the shop-wide one. */
    public function getSubscriber(): ?SubscriberInterface
    {
        return $this->type->getSubscriber();
    }

    public function getChannel(): string
    {
        return $this->type->getChannel();
    }

    public function isSingle(): bool
    {
        return true;
    }

    /** A cart reminder is marketing: the recipient can stop it. */
    public function isStoppable(): bool
    {
        return true;
    }

    public function authorize(string $reference, int $customerId): ?Target
    {
        $quoteId = $customerId ? $this->quoteId($reference) : $this->maskedQuoteId($reference);
        $quote = $quoteId ? $this->scope->find($quoteId, $customerId) : null;
        if (!$quote) {
            return null;
        }
        $storeId = (int)$quote['store_id'];
        $condition = sprintf('EXISTS (SELECT 1 FROM %s AS ck_q WHERE ck_q.entity_id = %d AND ck_q.store_id = %d'
            . ' AND ck_q.is_active = 1 AND %s)',
            $this->resource->getConnection()->quoteIdentifier($this->resource->getTableName('quote')), $quoteId, $storeId,
            $customerId ? sprintf('ck_q.customer_id = %d', $customerId) : 'ck_q.customer_id IS NULL');
        return new Target($quoteId, $storeId, $this->type->isEnabled($storeId), $condition);
    }

    public function orphanCondition(string $referenceIdColumn): string
    {
        return sprintf('NOT EXISTS (SELECT 1 FROM %s AS ck_q WHERE ck_q.entity_id = %s)',
            $this->resource->getConnection()->quoteIdentifier($this->resource->getTableName('quote')), $referenceIdColumn);
    }

    private function quoteId(string $reference): int
    {
        return ctype_digit($reference) ? (int)$reference : 0;
    }

    private function maskedQuoteId(string $reference): int
    {
        if ($reference === '') {
            return 0;
        }
        $mask = $this->quoteIdMaskFactory->create();
        $this->quoteIdMaskResource->load($mask, $reference, 'masked_id');
        return (int)$mask->getQuoteId();
    }
}
