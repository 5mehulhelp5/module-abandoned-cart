<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api;

/**
 * Resolves the current recipient for queue eligibility and delivery.
 * @api
 */
interface ReminderEmailResolverInterface
{
    /**
     * @param int $quoteId
     * @return string|null null when there is no recipient
     */
    public function resolve(int $quoteId): ?string;
}
