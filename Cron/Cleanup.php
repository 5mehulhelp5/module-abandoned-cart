<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Cron;

use CommerceKings\AbandonedCart\Model\RecipientHistory;
use Psr\Log\LoggerInterface;

/**
 * Daily: recipient history older than the 7-day cooldown. (Consent withdrawals are cleaned by
 * CommerceKings_Consent.)
 */
class Cleanup
{
    public function __construct(
        private readonly RecipientHistory $recipients,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): int
    {
        $recipients = $this->recipients->cleanUp();
        if ($recipients) {
            $this->logger->info(sprintf('CommerceKings_AbandonedCart: deleted %d old recipient record(s)', $recipients));
        }
        return $recipients;
    }
}
