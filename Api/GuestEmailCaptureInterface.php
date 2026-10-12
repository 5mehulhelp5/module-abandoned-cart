<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api;

/**
 * Stores the email a guest typed in checkout on their cart (only with the cookie-banner consent
 * the cart_reminder purpose of CommerceKings_Consent, read server-side), so an abandoned cart can be reminded. Called with an empty
 * email when the visitor switches the consent off - stored data is then cleared.
 * @api
 */
interface GuestEmailCaptureInterface
{
    /**
     * @param string $cartId masked guest cart id
     * @param string $email
     * @return bool true when stored (false: not stored / cleared)
     */
    public function capture(string $cartId, string $email): bool;
}
