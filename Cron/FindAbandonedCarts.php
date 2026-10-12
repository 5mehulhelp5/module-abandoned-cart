<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Cron;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\RecipientHistory;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

/**
 * Every 15 minutes: carts that have items and an email, were not ordered, have been untouched for
 * the configured delay and were never reminded -> one message per cart to the queue. The cart is
 * marked "queued" first, so the next run does not publish it again.
 *
 * Stale claims: a cart queued more than STALE_MINUTES ago but never reminded (publish failed, or the
 * message was lost) is released first, so this same run queues it again. A duplicate message is
 * harmless - the consumer's own claim lets only one delivery send.
 *
 * Addresses reminded within the cooldown are skipped here (RecipientHistory, keyed hash - computed in
 * PHP, never in SQL); the consumer's atomic address claim is the real guarantee. Carts are read page by
 * page, so carts waiting out a cooldown never fill the batch and hold up the others.
 */
class FindAbandonedCarts
{
    public const TOPIC = 'commercekings.abandoned_cart.remind';
    private const BATCH = 200;
    private const STALE_MINUTES = 60;

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly ConsentGuardInterface $guard,
        private readonly PublisherInterface $publisher,
        private readonly RecipientHistory $recipients,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): int
    {
        if (!$this->config->isEnabled()) {
            return 0;
        }
        $db = $this->resource->getConnection();
        $quote = $this->resource->getTableName('quote');

        $released = $db->update(
            $quote,
            ['ck_abandoned_queued_at' => null, 'updated_at' => new \Zend_Db_Expr('updated_at')],
            [
                'ck_abandoned_queued_at < NOW() - INTERVAL ' . self::STALE_MINUTES . ' MINUTE',
                'ck_abandoned_reminded_at IS NULL',
                'is_active = ?' => 1,
            ]
        );
        if ($released) {
            $this->logger->warning(sprintf('CommerceKings_AbandonedCart: released %d stale claim(s)', $released));
        }

        $due = $this->dueCarts();
        $published = 0;
        foreach ($due as $id) {
            // claim it first: only the run that flips queued_at from NULL publishes
            $claimed = $db->update(
                $quote,
                ['ck_abandoned_queued_at' => new \Zend_Db_Expr('NOW()'), 'updated_at' => new \Zend_Db_Expr('updated_at')],
                ['entity_id = ?' => (int)$id, 'ck_abandoned_queued_at IS NULL']
            );
            if ($claimed) {
                $this->publisher->publish(self::TOPIC, (string)$id);
                $published++;
            }
        }
        if ($published) {
            $this->logger->info(sprintf('CommerceKings_AbandonedCart: queued %d cart reminder(s)', $published));
        }
        return $published;
    }

    /**
     * Up to BATCH carts that may get a reminder now, oldest first, at most one per address.
     *
     * @return int[]
     */
    private function dueCarts(): array
    {
        $db = $this->resource->getConnection();
        $due = [];
        $emails = [];
        for ($page = 0; count($due) < self::BATCH; $page++) {
            // the DB session runs in UTC, like quote.updated_at
            $carts = $db->fetchPairs(
                $db->select()->from(['q' => $this->resource->getTableName('quote')], ['entity_id', 'customer_email'])
                    ->where('is_active = 1')
                    ->where('items_count > 0')
                    ->where("customer_email IS NOT NULL AND customer_email <> ''")
                    // a valid consent for purpose cart_reminder (not withdrawn, expired or from an older revision)
                    ->where('ck_abandoned_consent_id IS NOT NULL')
                    ->where($this->guard->validCondition('q.ck_abandoned_consent_id'))
                    ->where('ck_abandoned_reminded_at IS NULL')
                    ->where('ck_abandoned_queued_at IS NULL')
                    ->where('updated_at <= NOW() - INTERVAL ? MINUTE', $this->config->getDelayMinutes())
                    ->where('updated_at >= NOW() - INTERVAL ? DAY', $this->config->getMaxAgeDays())
                    ->order(['updated_at ASC', 'entity_id ASC'])
                    ->limit(self::BATCH, $page * self::BATCH)
            );
            // one reminder per address per cooldown, whichever cart it was for
            $recent = array_flip(array_map('mb_strtolower', array_map('trim', $this->recipients->recent(array_values($carts)))));
            foreach ($carts as $id => $email) {
                $address = mb_strtolower(trim((string)$email));
                if (!isset($recent[$address]) && !isset($emails[$address]) && count($due) < self::BATCH) {
                    $emails[$address] = true;
                    $due[] = (int)$id;
                }
            }
            if (count($carts) < self::BATCH) {
                break;
            }
        }
        return $due;
    }
}
