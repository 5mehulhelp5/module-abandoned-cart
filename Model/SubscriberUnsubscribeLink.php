<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\Data\UnsubscribeContext;
use CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;

/**
 * Default: the subscriber's unsubscribe link (Notification's SubscriberInterface) - with the consent bridge Consent's
 * signed withdrawal page and one-click handling; '' when there is none.
 */
class SubscriberUnsubscribeLink implements UnsubscribeLinkInterface
{
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly SubscriberInterface $subscriber
    ) {
    }

    public function getUrl(UnsubscribeContext $context): string
    {
        $subscription = $this->subscriptions->get((int)$context->getAttribute('subscription_id'));
        return $subscription === null ? '' : (string)$this->subscriber->getUnsubscribeUrl($subscription);
    }
}
