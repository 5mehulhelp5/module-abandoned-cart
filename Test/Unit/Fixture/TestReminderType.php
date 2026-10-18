<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Fixture;

use CommerceKings\AbandonedCart\Api\Data\ReminderMessage;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\ReminderTypeInterface;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;

/**
 * A channel module's reminder type, as a third party would write it against the @api only: its own code, channel,
 * purpose, permission, settings and text. Records the messages it was given.
 */
class TestReminderType implements ReminderTypeInterface
{
    /** @var ReminderMessage[] */
    public array $messages = [];

    /**
     * @param array<int, string|null> $recipients quote id => recipient (missing: '+381601234567')
     */
    public function __construct(
        private readonly string $code = 'cart_reminder_test',
        private readonly string $channel = 'sms',
        private readonly ?SubscriptionPermissionInterface $permission = null,
        private readonly ?SubscriberInterface $subscriber = null,
        private readonly bool $enabled = true,
        private readonly int $delay = 30,
        private readonly int $perHour = 0,
        private readonly ?ReminderPolicyInterface $policy = null,
        private readonly array $recipients = []
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getPurpose(): string
    {
        return $this->code;
    }

    public function getPermission(): ?SubscriptionPermissionInterface
    {
        return $this->permission;
    }

    public function getSubscriber(): ?SubscriberInterface
    {
        return $this->subscriber;
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->enabled;
    }

    public function getDelayMinutes(int $storeId): int
    {
        return $this->delay;
    }

    public function getMaxPerHour(): int
    {
        return $this->perHour;
    }

    public function getPolicy(): ReminderPolicyInterface
    {
        return $this->policy ?? new OncePerCart();
    }

    public function getRecipient(int $quoteId): ?string
    {
        return array_key_exists($quoteId, $this->recipients) ? $this->recipients[$quoteId] : '+381601234567';
    }

    public function createDelivery(ReminderMessage $message): Delivery
    {
        $this->messages[] = $message;
        return Delivery::text($message->storeId, $this->channel, $message->recipient,
            fn () => new DeliveryContent([], text: 'Your cart is waiting'), true, $message->isRecipientCurrent,
            true, $this->code);
    }
}
