<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api;

/**
 * Stores a guest checkout email only with valid cart_reminder consent, read server-side through
 * CommerceKings_Consent. An empty email or missing consent clears the reminder data from a guest
 * cart. Customer carts are never changed by this endpoint.
 * @api
 */
interface GuestEmailCaptureInterface
{
    /**
     * @param string $cartId masked guest cart id
     * @param string $email
     * @return bool true when stored, including an identical successful retry; false when refused or cleared
     */
    public function capture(string $cartId, string $email): bool;
}
