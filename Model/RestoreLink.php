<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\RestoreLinkInterface;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\Notification\Api\SubscriberInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;

/**
 * The email reminder's signed restore link only when its subscriber can be identified (RestoreAccess hands the cart
 * to that visitor only); otherwise, and for every other reminder type, Magento's cart page - the visitor's own
 * session or account cart.
 */
class RestoreLink implements RestoreLinkInterface
{
    public function __construct(
        private readonly UrlInterface $url,
        private readonly LinkSignature $signature,
        private readonly SubscriberInterface $subscriber
    ) {
    }

    public function getUrl(Quote $quote, string $typeCode): string
    {
        $this->url->setScope($quote->getStoreId());
        if ($typeCode !== CartReminder::CODE || !$this->subscriber->identifiesVisitor()) {
            return $this->url->getUrl('checkout/cart', ['_nosid' => true]);
        }
        return $this->url->getUrl('cartreminder/cart/restore', [
            '_query' => [
                'q' => (int)$quote->getId(),
                't' => $this->signature->sign('restore', (int)$quote->getId()),
            ],
            '_nosid' => true,
        ]);
    }
}
