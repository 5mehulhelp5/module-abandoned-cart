<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api;

/**
 * Stores a guest reminder email (a CommerceKings_Notification contact, linked from the cart's reminder state)
 * only with valid cart_reminder consent, read server-side through CommerceKings_Consent. An empty email or
 * missing consent clears the reminder data from a guest cart. Magento's quote, its checkout email and
 * customer carts are never changed by this endpoint.
 * @api
 */
interface GuestEmailCaptureInterface
{
    /**
     * @param string $cartId masked guest cart id
     * @param mixed $email raw input; must be a string, with an empty string clearing capture
     * @return bool true when stored, including an identical successful retry; false when refused or cleared
     * @throws \Magento\Framework\Exception\InputException
     */
    public function capture(string $cartId, mixed $email): bool;
}
