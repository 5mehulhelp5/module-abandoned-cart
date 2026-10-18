<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api;

use CommerceKings\AbandonedCart\Api\Data\CartFacts;
use Magento\Quote\Model\Quote;

/**
 * The cart's facts for a reminder on any channel (e.g. a text: name, first product, total), the same the email
 * shows. Call it inside the delivery's content closure, so prices and URLs are in the store's environment.
 *
 * @api
 */
interface CartDataInterface
{
    public function get(Quote $quote): CartFacts;
}
