<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Cron;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\QueueInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every minute: keeps up to "Maximum pending reminders" carts queued - carts that have items and a subscription with a
 * valid consent, were not ordered, have been untouched for the configured delay, are unexpired under Magento's quote
 * lifetime and are eligible under the reminder policy - one message per cart (its CommerceKings_Notification
 * subscription id) to Notification's queue. Each run only fills what the consumers sent since the last one, so the
 * number of consumers sets the pace. "Maximum reminders per hour" brakes it for the mail provider.
 *
 * One finder at a time: a second run while one fills (overlapping cron, a manual run, another server) exits.
 * The cart is marked "queued" first, so no run publishes it twice.
 *
 * Stale claims: a cart queued more than STALE_MINUTES ago but never reminded (publish failed, the message was lost,
 * or the consumers were stopped) is released first, so this same run queues it again. The pending limit counts carts,
 * not broker messages: while consumers are stopped, a released cart's first message may still wait in the broker. A
 * duplicate message is harmless - the consumer's own claim lets only one delivery send.
 *
 * Recipient cooldown suppresses recent addresses. Store settings and policy
 * conditions are repeated in the atomic queue claim so a stale selection cannot authorize delivery.
 */
class FindAbandonedCarts
{
    private const BATCH = 200;
    private const STALE_MINUTES = 60;
    private const MAX_RUN_SECONDS = 30;
    private const LOCK = 'commercekings_abandoned_cart_finder';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly ConsentGuardInterface $guard,
        private readonly QueueInterface $queue,
        private readonly ReminderPolicyInterface $policy,
        private readonly QuoteLifetime $lifetime,
        private readonly StoreManagerInterface $stores,
        private readonly LoggerInterface $logger,
        private readonly ReminderEmailResolverInterface $recipients,
        private readonly CooldownInterface $cooldown,
        private readonly CartTable $carts,
        private readonly LockManagerInterface $locks,
        private readonly Random $random
    ) {
    }

    public function execute(): int
    {
        if ($this->storeCondition() === '0 = 1') {
            return 0;
        }
        // counting free room and filling it must not overlap, or two runs fill the same room
        if (!$this->locks->lock(self::LOCK, 0)) {
            $this->logger->info('CommerceKings_AbandonedCart: another finder is running');
            return 0;
        }
        try {
            return $this->fill();
        } finally {
            $this->locks->unlock(self::LOCK);
        }
    }

    private function fill(): int
    {
        $db = $this->resource->getConnection();
        $released = $db->update(
            $this->carts->joined(),
            ['c.queued_at' => null, 'c.queue_token' => null],
            [
                'c.queued_at < NOW() - INTERVAL ' . self::STALE_MINUTES . ' MINUTE',
                $this->storeCondition(),
                'q.is_active = ?' => 1,
            ]
        );
        if ($released) {
            $this->logger->warning(sprintf('CommerceKings_AbandonedCart: released %d stale claim(s)', $released));
        }

        $pending = $this->pending();
        $room = $this->config->getMaxPending() - $pending;
        $perHour = $this->config->getMaxPerHour();
        if ($perHour > 0) {
            $room = min($room, $perHour - $pending - $this->sentLastHour());
        }
        $published = 0;
        $deadline = microtime(true) + self::MAX_RUN_SECONDS;
        if ($room > 0) {
            foreach ($this->dueCarts($deadline) as $id => $subscriptionId) {
                if (microtime(true) >= $deadline) {
                    break; // the next run continues; nothing was claimed for the rest
                }
                // claim it first: only the run that flips queued_at from NULL publishes
                $this->carts->ensure($id);
                $claimed = $db->update(
                    $this->carts->joined(),
                    ['c.queued_at' => new Expression('NOW()'),
                        'c.queue_token' => $this->random->getRandomString(32, '0123456789abcdef')],
                    array_merge(['c.quote_id = ?' => $id, 's.subscription_id = ?' => $subscriptionId],
                        $this->eligibleConditions())
                );
                if ($claimed) {
                    $this->queue->publish(CartReminder::CODE, (string)$subscriptionId);
                    $published++;
                }
                if ($published >= $room) {
                    break;
                }
            }
        }
        if ($published) {
            $this->logger->info(sprintf('CommerceKings_AbandonedCart: queued %d cart reminder(s), %d were pending',
                $published, $pending));
        }
        return $published;
    }

    /**
     * Carts queued and not sent since, within the stale window, whose subscription still has a valid consent:
     * sent, failed, skipped and withdrawn carts do not count.
     */
    private function pending(): int
    {
        $db = $this->resource->getConnection();
        return (int)$db->fetchOne($this->carts->select([new Expression('COUNT(*)')])
            ->where('c.queued_at IS NOT NULL')
            ->where('c.reminded_at IS NULL OR c.reminded_at < c.queued_at')
            ->where('c.queued_at >= NOW() - INTERVAL ' . self::STALE_MINUTES . ' MINUTE')
            ->where($this->guard->validCondition('s.consent_id')));
    }

    private function sentLastHour(): int
    {
        $db = $this->resource->getConnection();
        return (int)$db->fetchOne($db->select()->from($this->carts->name(), new Expression('COUNT(*)'))
            ->where('reminded_at >= NOW() - INTERVAL 1 HOUR'));
    }

    /**
     * Yield eligible carts as they are resolved, so a slow scan retains progress before its deadline.
     * The cursor follows activity and cart id: claiming removes rows from the selection, so offsets would skip carts.
     *
     * @return \Generator<int, int> quote id => subscription id
     */
    private function dueCarts(float $deadline): \Generator
    {
        $db = $this->resource->getConnection();
        $select = $this->carts->select([], ['entity_id', 'updated_at'], ['subscription_id']);
        foreach ($this->eligibleConditions() as $condition) {
            $select->where($condition);
        }
        $select->order(['q.updated_at ASC', 'q.entity_id ASC']);
        $updatedAt = null;
        $lastId = 0;
        do {
            if (microtime(true) >= $deadline) {
                return;
            }
            $page = clone $select;
            if ($updatedAt !== null) {
                $page->where($db->quoteInto('q.updated_at > ?', $updatedAt) . ' OR ('
                    . $db->quoteInto('q.updated_at = ?', $updatedAt)
                    . $db->quoteInto(' AND q.entity_id > ?)', $lastId));
            }
            $rows = $db->fetchAll($page->limit(self::BATCH, 0));
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    return;
                }
                $updatedAt = (string)$row['updated_at'];
                $lastId = (int)$row['entity_id'];
                $email = $this->recipients->resolve($lastId);
                if ($email !== null && !$this->cooldown->isRecent($email)) {
                    yield $lastId => (int)$row['subscription_id'];
                }
            }
        } while (count($rows) === self::BATCH);
    }

    private function eligibleConditions(): array
    {
        return [
            'q.is_active = 1',
            'q.items_count > 0',
            $this->guard->validCondition('s.consent_id'),
            'c.queued_at IS NULL',
            $this->storeCondition(),
        ];
    }

    private function storeCondition(): string
    {
        $branches = [];
        foreach ($this->stores->getStores() as $store) {
            $id = (int)$store->getId();
            if (!$this->config->isEnabled($id)) {
                continue;
            }
            $delay = $this->config->getDelayMinutes($id);
            $branches[] = sprintf('(q.store_id = %d AND q.updated_at <= NOW() - INTERVAL %d MINUTE'
                . ' AND s.captured_at <= NOW() - INTERVAL %d MINUTE AND (%s) AND (%s))',
                $id,
                $delay,
                $delay,
                $this->lifetime->validCondition('q.updated_at', $id),
                $this->policy->dueCondition(new ReminderQuery($id, 'c', 'q'))
            );
        }
        return $branches ? '(' . implode(' OR ', $branches) . ')' : '0 = 1';
    }
}
