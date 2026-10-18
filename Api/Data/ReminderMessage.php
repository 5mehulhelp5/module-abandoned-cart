<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api\Data;

use Magento\Quote\Model\Quote;

/**
 * One claimed cart, handed to ReminderTypeInterface::createDelivery(). Optional attributes may grow without changing
 * the method; a missing attribute returns null.
 *
 * @api
 */
class ReminderMessage
{
    /**
     * @param \Closure(): Quote $quote the cart, loaded fresh - call it inside the delivery's content closure (store
     *     environment), never earlier
     * @param \Closure(): bool $isRecipientCurrent re-checked by the sender after its cooldown claim
     * @param string $permissionRef the subscription's permission reference (its unsubscribe link)
     * @param array<string, mixed> $attributes more context, e.g. subscription_id
     */
    public function __construct(
        public readonly int $quoteId,
        public readonly int $storeId,
        public readonly int $subscriptionId,
        public readonly string $recipient,
        public readonly string $permissionRef,
        public readonly \Closure $quote,
        public readonly \Closure $isRecipientCurrent,
        private readonly array $attributes = []
    ) {
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
