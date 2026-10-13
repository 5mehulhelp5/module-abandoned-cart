<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api\Data;

/**
 * Read-only delivery-state snapshot. Optional attributes may grow without changing policy methods;
 * existing policies use the fields they understand. Policies must handle absent optional data.
 * @api
 */
class ReminderState
{
    /**
     * @param int $quoteId cart identifier; policies may use it to read their own delivery history
     * @param int $storeId store that owns the cart
     * @param string|null $lastRemindedAt UTC Y-m-d H:i:s; null means the cart was never reminded
     * @param array<string, mixed> $attributes additional state; a missing attribute returns null
     */
    public function __construct(
        public readonly int $quoteId,
        public readonly int $storeId,
        public readonly ?string $lastRemindedAt,
        private readonly array $attributes = []
    ) {
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
