<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Fixture;

use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;

/**
 * A third-party implementation of the frozen base contract. Keep these signatures independent of
 * production policy changes: adding required methods or incompatible parameters must fail tests.
 */
class CompatibleReminderPolicy implements ReminderPolicyInterface
{
    public function getDescription(): string
    {
        return 'Compatibility fixture';
    }

    public function canSend(ReminderState $state): bool
    {
        return false;
    }

    public function dueCondition(ReminderQuery $query): string
    {
        return $query->column('entity_id') . ' < 0';
    }
}
