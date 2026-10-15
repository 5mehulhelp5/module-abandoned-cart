<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api\Data;

/**
 * Everything known about the reminder when its unsubscribe link is built. Read-only; attributes may grow without
 * changing UnsubscribeLinkInterface, so a link adapter uses what it needs and treats a missing attribute (null)
 * as unavailable. Current attributes: quote_id, customer_id (null for guests), subscription_id, email.
 *
 * @api
 */
class UnsubscribeContext
{
    /**
     * @param string $consentId the CommerceKings_Consent consent the reminder was sent under
     * @param int $storeId store the email is sent from
     * @param array<string, mixed> $attributes additional context; a missing attribute returns null
     */
    public function __construct(
        public readonly string $consentId,
        public readonly int $storeId,
        private readonly array $attributes = []
    ) {
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
