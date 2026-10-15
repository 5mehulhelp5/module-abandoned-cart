<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api;

use CommerceKings\AbandonedCart\Api\Data\UnsubscribeContext;

/**
 * The unsubscribe link in a reminder email ({{var optout_url}}, also the one-click header when
 * CommerceKings_Notification's "One-click unsubscribe" is on).
 * The default uses CommerceKings_Consent's signed withdrawal link and page. Replace it through a DI preference to
 * use any link format: read what you need from the context. A replacement owns its page: it must make links
 * unguessable, accept the mail client's one-click POST (List-Unsubscribe=One-Click) when that is enabled, and
 * revoke the subscription: with the consent bridge, CommerceKings\Consent\Api\ConsentInterface::withdraw(
 * $context->permissionRef, 'email'); without it, Notification's SubscriptionRemovalInterface::remove() with the
 * subscription_id attribute.
 *
 * @api
 */
interface UnsubscribeLinkInterface
{
    public function getUrl(UnsubscribeContext $context): string;
}
