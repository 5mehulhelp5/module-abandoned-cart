<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api;

use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;

/**
 * Delivery frequency per cart. Consent, cart ownership, abandonment delay and quote expiry remain
 * mandatory checks outside this policy. The default implementation allows one reminder per cart.
 * @api
 */
interface ReminderPolicyInterface
{
    /** Translated, human-readable delivery frequency for the read-only admin preview. */
    public function getDescription(): string;

    /** Whether the delivery-state snapshot permits another delivery. */
    public function canSend(ReminderState $state): bool;

    /**
     * Equivalent SQL for finder selection and atomic claims. The context describes the reminder
     * state's table alias and store. Implementations must keep this condition consistent with canSend(),
     * including after a previous reminder. New state belongs in the context or a policy's own
     * repository; additional required interface methods would break existing implementations.
     */
    public function dueCondition(ReminderQuery $query): string;
}
