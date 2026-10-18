<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api;

use CommerceKings\AbandonedCart\Api\Data\ReminderMessage;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;

/**
 * One cart reminder on one channel: the email reminder ships with this module (code "cart_reminder"); a channel
 * module adds another (e.g. "cart_reminder_sms") by registering it in Model\ReminderTypePool ("types": code =>
 * implementation) and wiring the virtual types described in docs/extending.md. The finder, the claim, retries, the
 * hourly limit, quiet hours and the send history (Notification's delivery state, per type and cart) are shared;
 * everything below is the type's own.
 *
 * @api
 */
interface ReminderTypeInterface
{
    /** The notification and subscription type code, e.g. "cart_reminder_sms"; one per channel. */
    public function getCode(): string;

    /** The channel it is sent on (Notification's Channel::EMAIL, SMS, WHATSAPP, VIBER, or a module's own code). */
    public function getChannel(): string;

    /** What the shopper agrees to (SubscriptionTypeInterface::getPurpose()). */
    public function getPurpose(): string;

    /** Its own permission (e.g. an AddressConsent virtual type for a checkbox per number); null: the shop-wide one. */
    public function getPermission(): ?SubscriptionPermissionInterface;

    /** The subscriber behind its own permission; null: the shop-wide one. */
    public function getSubscriber(): ?SubscriberInterface;

    /** Sends in this store at all; a store where it is off takes no sign-ups and queues nothing. */
    public function isEnabled(int $storeId): bool;

    /** Minutes without cart or sign-up activity before a cart is due (at least 1). */
    public function getDelayMinutes(int $storeId): int;

    /** Reminders of this type per hour across all stores (its provider's limit); 0: no limit. */
    public function getMaxPerHour(): int;

    /** How often a cart may get this reminder; the default sends one per cart. */
    public function getPolicy(): ReminderPolicyInterface;

    /** The cart's current recipient on the channel (e.g. the subscribed number); null: none, the cart is skipped. */
    public function getRecipient(int $quoteId): ?string;

    /**
     * The delivery for one claimed cart: the type's template or text and content (built inside the store, from the
     * fresh cart in $message). Pass $message->recipient, $message->isRecipientCurrent, stoppable and the type code.
     */
    public function createDelivery(ReminderMessage $message): Delivery;
}
