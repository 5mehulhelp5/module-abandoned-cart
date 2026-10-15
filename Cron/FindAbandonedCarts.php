<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Cron;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\RecipientHistory;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every 15 minutes: carts that have items and an email, were not ordered, have been untouched for
 * the configured delay, unexpired under Magento's quote lifetime and eligible under the reminder
 * policy -> one message per cart to the queue. The cart is
 * marked "queued" first, so the next run does not publish it again.
 *
 * Stale claims: a cart queued more than STALE_MINUTES ago but never reminded (publish failed, or the
 * message was lost) is released first, so this same run queues it again. A duplicate message is
 * harmless - the consumer's own claim lets only one delivery send.
 *
 * Recipient cooldown suppresses recent addresses. Store settings and policy
 * conditions are repeated in the atomic queue claim so a stale selection cannot authorize delivery.
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
        private readonly ReminderPolicyInterface $policy,
        private readonly QuoteLifetime $lifetime,
        private readonly StoreManagerInterface $stores,
        private readonly LoggerInterface $logger,
        private readonly ReminderEmailResolverInterface $recipients,
        private readonly RecipientHistory $history
    ) {
    }

    public function execute(): int
    {
        if ($this->storeCondition('') === '0 = 1') {
            return 0;
        }
        $db = $this->resource->getConnection();
        $quote = $this->resource->getTableName('quote');

        $released = $db->update(
            $quote,
            ['ck_abandoned_queued_at' => null, 'updated_at' => new Expression('updated_at')],
            [
                'ck_abandoned_queued_at < NOW() - INTERVAL ' . self::STALE_MINUTES . ' MINUTE',
                $this->storeCondition(''),
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
                ['ck_abandoned_queued_at' => new Expression('NOW()'), 'updated_at' => new Expression('updated_at')],
                array_merge(['entity_id = ?' => (int)$id], $this->eligibleConditions(''))
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
     * Up to BATCH eligible carts, oldest first, excluding recipients still in cooldown.
     *
     * @return int[]
     */
    private function dueCarts(): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from(['q' => $this->resource->getTableName('quote')], ['entity_id']);
        foreach ($this->eligibleConditions('q.') as $condition) {
            $select->where($condition);
        }
        $select->order(['q.updated_at ASC', 'q.entity_id ASC']);
        $due = [];
        $offset = 0;
        // Read pages before claiming, so carts without a resolvable email cannot block later carts.
        do {
            $ids = $db->fetchCol((clone $select)->limit(self::BATCH, $offset));
            foreach ($ids as $id) {
                $email = $this->recipients->resolve((int)$id);
                if ($email !== null && !$this->history->isRecent($email)) {
                    $due[] = (int)$id;
                    if (count($due) === self::BATCH) {
                        return $due;
                    }
                }
            }
            $offset += self::BATCH;
        } while (count($ids) === self::BATCH);
        return $due;
    }

    private function eligibleConditions(string $prefix): array
    {
        return [
            $prefix . 'is_active = 1',
            $prefix . 'items_count > 0',
            $prefix . 'ck_abandoned_consent_id IS NOT NULL',
            $this->guard->validCondition($prefix . 'ck_abandoned_consent_id'),
            $prefix . 'ck_abandoned_queued_at IS NULL',
            $this->storeCondition($prefix),
        ];
    }

    private function storeCondition(string $prefix): string
    {
        $branches = [];
        foreach ($this->stores->getStores() as $store) {
            $id = (int)$store->getId();
            if (!$this->config->isEnabled($id)) {
                continue;
            }
            $emailIdle = '(' . $prefix . 'customer_id IS NOT NULL OR ' . $prefix . 'ck_abandoned_email_updated_at IS NULL OR '
                . $prefix . 'ck_abandoned_email_updated_at <= NOW() - INTERVAL ' . $this->config->getDelayMinutes($id) . ' MINUTE)';
            $branches[] = sprintf('(%sstore_id = %d AND %supdated_at <= NOW() - INTERVAL %d MINUTE AND (%s) AND (%s) AND (%s))',
                $prefix,
                $id,
                $prefix,
                $this->config->getDelayMinutes($id),
                $emailIdle,
                $this->lifetime->validCondition($prefix . 'updated_at', $id),
                $this->policy->dueCondition(new ReminderQuery($id, rtrim($prefix, '.')))
            );
        }
        return $branches ? '(' . implode(' OR ', $branches) . ')' : '0 = 1';
    }
}
