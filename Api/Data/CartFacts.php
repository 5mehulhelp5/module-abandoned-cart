<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api\Data;

/**
 * Plain facts about a cart for a message on any channel (CartDataInterface): no markup, prices formatted in the
 * cart's currency as the cart shows them, only the store's own option choices (never text a visitor typed).
 *
 * @api
 */
class CartFacts
{
    /**
     * @param string $firstName the greeting name: first word, letters only, at most 20 characters; '' when unusable
     * @param array<int, array{name: string, qty: float, price: string, image: string, options: string[]}> $items
     * @param string $grandTotal formatted, e.g. "€42.00"
     */
    public function __construct(
        public readonly string $firstName,
        public readonly array $items,
        public readonly string $grandTotal,
        public readonly string $storeUrl
    ) {
    }
}
