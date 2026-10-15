<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\Data\UnsubscribeContext;
use CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;

/**
 * Default: the link of the subscription's permission (Notification's SubscriptionPermissionInterface) - with the
 * consent bridge Consent's signed withdrawal page and one-click handling; '' when the permission has none.
 */
class PermissionUnsubscribeLink implements UnsubscribeLinkInterface
{
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly SubscriptionPermissionInterface $permission
    ) {
    }

    public function getUrl(UnsubscribeContext $context): string
    {
        $subscription = $this->subscriptions->get((int)$context->getAttribute('subscription_id'));
        return $subscription === null ? '' : (string)$this->permission->getUnsubscribeUrl($subscription);
    }
}
