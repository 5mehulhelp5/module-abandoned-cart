<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\ReminderTypeInterface;

/**
 * The cart reminders the finder queues, one per channel (di.xml array argument "types": code => ReminderTypeInterface).
 * This module registers the email reminder ("cart_reminder"); a channel module adds its own.
 *
 * @api the "types" di.xml argument is a stable extension point
 */
class ReminderTypePool
{
    /** @param array<string, ReminderTypeInterface> $types */
    public function __construct(
        private readonly array $types = []
    ) {
        foreach ($types as $code => $type) {
            if (!$type instanceof ReminderTypeInterface || $type->getCode() !== $code) {
                throw new \InvalidArgumentException(sprintf(
                    'Reminder type "%s" must implement ReminderTypeInterface and be registered under its code.', $code));
            }
        }
    }

    /** @return array<string, ReminderTypeInterface> by code */
    public function getAll(): array
    {
        return $this->types;
    }

    public function get(string $code): ?ReminderTypeInterface
    {
        return $this->types[$code] ?? null;
    }
}
