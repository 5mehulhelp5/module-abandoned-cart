<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Cron;

use CommerceKings\AbandonedCart\Model\RecipientHistory;

/** Prune recipient suppression rows after the configured cooldown. */
class Cleanup
{
    public function __construct(private readonly RecipientHistory $recipients)
    {
    }

    public function execute(): int
    {
        return $this->recipients->cleanUp();
    }
}
