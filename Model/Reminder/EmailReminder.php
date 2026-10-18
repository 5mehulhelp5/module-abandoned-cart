<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model\Reminder;

use CommerceKings\AbandonedCart\Api\Data\ReminderMessage;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\ReminderTypeInterface;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Model\Config as NotificationConfig;

/**
 * The built-in email reminder ("cart_reminder"): this module's settings (Stores > Configuration > CommerceKings >
 * Abandoned cart), its email template and sender identity, the shop-wide permission (opt-out, or the consent bridge's
 * banner consent) and the cart's email (ReminderEmailResolverInterface).
 */
class EmailReminder implements ReminderTypeInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ReminderEmailResolverInterface $recipients,
        private readonly ReminderData $reminderData,
        private readonly ReminderPolicyInterface $policy,
        private readonly NotificationConfig $notificationConfig
    ) {
    }

    public function getCode(): string
    {
        return CartReminder::CODE;
    }

    public function getChannel(): string
    {
        return Channel::EMAIL;
    }

    public function getPurpose(): string
    {
        return Config::PURPOSE;
    }

    public function getPermission(): ?SubscriptionPermissionInterface
    {
        return null;
    }

    public function getSubscriber(): ?SubscriberInterface
    {
        return null;
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->config->isEnabled($storeId);
    }

    public function getDelayMinutes(int $storeId): int
    {
        return $this->config->getDelayMinutes($storeId);
    }

    public function getMaxPerHour(): int
    {
        return $this->config->getMaxPerHour();
    }

    public function getPolicy(): ReminderPolicyInterface
    {
        return $this->policy;
    }

    public function getRecipient(int $quoteId): ?string
    {
        return $this->recipients->resolve($quoteId);
    }

    public function createDelivery(ReminderMessage $message): Delivery
    {
        $storeId = $message->storeId;
        $email = $message->recipient;
        return new Delivery(
            $storeId,
            $this->config->getTemplate($storeId),
            $this->config->getIdentity($storeId),
            $email,
            function () use ($message, $email, $storeId): DeliveryContent {
                $quote = ($message->quote)();
                $vars = $this->reminderData->build($quote, $message->permissionRef,
                    ['subscription_id' => $message->subscriptionId, 'email' => $email]);
                // one-click: the consent withdrawal link (opt-in); without one, Notification uses the stop link
                return new DeliveryContent($vars, $vars['customer_name'] ?? null,
                    ($vars['optout_url'] ?? '') !== '' && $this->notificationConfig->isOneClickUnsubscribeEnabled($storeId)
                        ? $vars['optout_url'] : null);
            },
            true,
            $message->isRecipientCurrent,
            Channel::EMAIL,
            true,
            CartReminder::CODE
        );
    }
}
