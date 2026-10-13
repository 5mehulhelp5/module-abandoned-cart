<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model\ReminderPolicy;

use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;

/** One successful reminder for each cart; another cart with the same address is independent. */
class OncePerCart implements ReminderPolicyInterface
{
    public function getDescription(): string
    {
        return (string)__('1 (one-time)');
    }

    public function canSend(ReminderState $state): bool
    {
        return $state->lastRemindedAt === null;
    }

    public function dueCondition(ReminderQuery $query): string
    {
        return $query->column('ck_abandoned_reminded_at') . ' IS NULL';
    }
}
