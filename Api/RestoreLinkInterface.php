<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api;

use Magento\Quote\Model\Quote;

/**
 * The link in a reminder that brings the shopper back to the cart. For the email reminder a signed restore link when
 * the subscriber can be identified (it restores the cart in that browser only), else the cart page; for another
 * type the cart page (the visitor's own session or account cart) - a later version may give those a secure link
 * without changing this method.
 *
 * @api
 */
interface RestoreLinkInterface
{
    /** @param string $typeCode ReminderTypeInterface::getCode() of the reminder the link is for */
    public function getUrl(Quote $quote, string $typeCode): string;
}
