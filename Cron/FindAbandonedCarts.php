<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Cron;

use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\ReminderTypeInterface;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\AbandonedCart\Model\ReminderTypePool;
use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\QuietHoursInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every minute, for each reminder type (Model\ReminderTypePool: the email reminder, and any channel module's): carts
 * that have items and a subscription with a valid permission, were not ordered, have been untouched
 * for the configured delay, are unexpired under Magento's quote lifetime and are eligible under the reminder policy
 * -> one message per cart (its CommerceKings_Notification subscription id) to Notification's queue. The queue holds
 * the backlog and the consumers set the pace; a run publishes for at most MAX_RUN_SECONDS and the next one continues.
 *
 * The cart is marked "queued" in the same statement that checks it is still due, so no run - also not two at once -
 * publishes it twice. The mark means "waiting in the queue": the reminder clears it when it sends (so a recurring
 * policy can schedule the next one) and when it skips (so the cart can be found again later).
 * A failed publish clears the mark at once and the exception reaches cron: the next run publishes again. If the
 * broker had accepted the message after all, the copy is harmless - the reminder's own claim sends once.
 * Safety net: a cart still marked after STUCK_HOURS without a reminder (a consumer crashed holding its message) is
 * found again.
 *
 * "Maximum reminders per hour": a run only publishes what still fits in the hour (sent plus validly queued); the reminder
 * checks it again when sending. Recipient cooldown suppresses recent addresses; such a cart is left out for an hour
 * (skip_until) instead of being scanned again on every run. A store in quiet hours for every recipient on the
 * reminder's channel is left out while they last; a reminder held by them waits in skip_until until they end. Store
 * settings and policy conditions are repeated in the queue claim so a stale selection cannot authorize delivery.
 * Each type has its own settings, policy, hourly limit and send history; the run's time budget is shared.
 */
class FindAbandonedCarts
{
    private const BATCH = 200;
    private const STUCK_HOURS = 24;
    private const MAX_RUN_SECONDS = 30;
    /** a cart whose recipient is in cooldown is checked again after this, not on every run */
    private const SKIP_MINUTES = 60;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SubscriptionPermissionInterface $shopWidePermission,
        private readonly QueueInterface $queue,
        private readonly QuoteLifetime $lifetime,
        private readonly StoreManagerInterface $stores,
        private readonly LoggerInterface $logger,
        private readonly CooldownInterface $cooldown,
        private readonly CartTable $carts,
        private readonly QuietHoursInterface $quietHours,
        private readonly ReminderTypePool $types
    ) {
    }

    public function execute(): int
    {
        // one time budget for the run, started where the first type begins publishing
        $deadline = null;
        $published = 0;
        foreach ($this->types->getAll() as $type) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break; // the next run continues with the remaining types
            }
            $published += $this->queueType($type, $deadline);
        }
        return $published;
    }

    private function queueType(ReminderTypeInterface $type, ?float &$deadline): int
    {
        if ($this->storeCondition($type) === '0 = 1') {
            return 0;
        }
        $carts = $this->carts->forType($type->getCode());
        $db = $this->resource->getConnection();
        $stuck = $db->update($carts->name(), ['queued_at' => null],
            array_merge(['queued_at < NOW() - INTERVAL ' . self::STUCK_HOURS . ' HOUR'], $carts->ofType()));
        if ($stuck) {
            $this->logger->warning(sprintf('CommerceKings_AbandonedCart: %d cart(s) waited over %d hours, queued again',
                $stuck, self::STUCK_HOURS));
        }
        $room = PHP_INT_MAX;
        $perHour = $type->getMaxPerHour();
        if ($perHour > 0) {
            $room = $perHour - $this->sentOrQueuedLastHour($type, $carts);
            if ($room <= 0) {
                return 0;
            }
        }
        $published = 0;
        $deadline ??= microtime(true) + self::MAX_RUN_SECONDS;
        foreach ($this->dueCarts($type, $carts, $deadline) as $id => $subscriptionId) {
            if (microtime(true) >= $deadline) {
                break; // the next run continues; nothing was claimed for the rest
            }
            // claim it first: only the run that flips queued_at from NULL publishes
            $carts->ensure($id);
            $claimed = $db->update(
                $carts->joined(),
                ['c.queued_at' => new Expression('NOW()')],
                array_merge($carts->key($id, 'c'), ['s.subscription_id = ?' => $subscriptionId],
                    $this->eligibleConditions($type))
            );
            if (!$claimed) {
                continue;
            }
            try {
                $this->queue->publish($type->getCode(), (string)$subscriptionId);
            } catch (\Throwable $error) {
                $db->update($carts->name(), ['queued_at' => null], $carts->key($id));
                throw $error;
            }
            if (++$published >= $room) {
                break;
            }
        }
        if ($published) {
            $this->logger->info(sprintf('CommerceKings_AbandonedCart: queued %d cart reminder(s)%s', $published,
                $type->getCode() === CartReminder::CODE ? '' : ' (' . $type->getCode() . ')'));
        }
        return $published;
    }

    /** The type's own permission, else the shop-wide one - as Notification resolves it for the subscription type. */
    private function permission(ReminderTypeInterface $type): SubscriptionPermissionInterface
    {
        return $type->getPermission() ?? $this->shopWidePermission;
    }

    /**
     * Reminders sent in the last hour plus carts waiting in the queue whose subscription still has a valid permission:
     * a withdrawn cart's message is dropped unsent, so it does not take a place in the hour. Per type.
     */
    private function sentOrQueuedLastHour(ReminderTypeInterface $type, CartTable $carts): int
    {
        $db = $this->resource->getConnection();
        $sent = (int)$db->fetchOne($db->select()->from($carts->name(), new Expression('COUNT(*)'))
            ->where('type = ?', $type->getCode())->where('sent_at >= NOW() - INTERVAL 1 HOUR'));
        $queued = (int)$db->fetchOne($carts->select([new Expression('COUNT(*)')])
            ->where('c.queued_at IS NOT NULL')
            ->where($this->permission($type)->validCondition('s.permission_ref')));
        return $sent + $queued;
    }

    /**
     * Yield eligible carts as they are resolved, so a slow scan retains progress before its deadline.
     * The cursor follows activity and cart id: claiming removes rows from the selection, so offsets would skip carts.
     *
     * @return \Generator<int, int> quote id => subscription id
     */
    private function dueCarts(ReminderTypeInterface $type, CartTable $carts, float $deadline): \Generator
    {
        $db = $this->resource->getConnection();
        $select = $carts->select([], ['entity_id', 'updated_at'], ['subscription_id']);
        foreach ($this->eligibleConditions($type) as $condition) {
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
                $recipient = $type->getRecipient($lastId);
                if ($recipient !== null && !$this->cooldown->isRecentFor($type->getChannel(), $recipient)) {
                    yield $lastId => (int)$row['subscription_id'];
                } else {
                    $this->skip($carts, $lastId);
                }
            }
        } while (count($rows) === self::BATCH);
    }

    /**
     * No address or one in the recipient cooldown: leave the cart out of the selection for SKIP_MINUTES, so carts that
     * cannot be sent yet are not scanned (oldest first) on every run ahead of newer ones.
     */
    private function skip(CartTable $carts, int $quoteId): void
    {
        $carts->ensure($quoteId);
        $this->resource->getConnection()->update($carts->name(),
            ['skip_until' => new Expression('NOW() + INTERVAL ' . self::SKIP_MINUTES . ' MINUTE')],
            $carts->key($quoteId));
    }

    private function eligibleConditions(ReminderTypeInterface $type): array
    {
        return [
            'q.is_active = 1',
            'q.items_count > 0',
            $this->permission($type)->validCondition('s.permission_ref'),
            'c.queued_at IS NULL',
            '(c.skip_until IS NULL OR c.skip_until <= NOW())',
            '(c.failed_sends IS NULL OR c.failed_sends <= ' . count(CartTable::RETRY_MINUTES) . ')',
            $this->storeCondition($type),
        ];
    }

    private function storeCondition(ReminderTypeInterface $type): string
    {
        $branches = [];
        foreach ($this->stores->getStores() as $store) {
            $id = (int)$store->getId();
            // a store in quiet hours for every recipient on the reminder's channel queues nothing until they end;
            // quiet hours per recipient (a preference) answer false here and the sender holds each reminder
            if (!$type->isEnabled($id) || $this->quietHours->isQuiet($id, $type->getChannel(), null)) {
                continue;
            }
            $delay = $type->getDelayMinutes($id);
            $branches[] = sprintf('(q.store_id = %d AND q.updated_at <= NOW() - INTERVAL %d MINUTE'
                . ' AND s.captured_at <= NOW() - INTERVAL %d MINUTE AND (%s) AND (%s))',
                $id,
                $delay,
                $delay,
                $this->lifetime->validCondition('q.updated_at', $id),
                $type->getPolicy()->dueCondition(new ReminderQuery($id, 'c', 'q'))
            );
        }
        return $branches ? '(' . implode(' OR ', $branches) . ')' : '0 = 1';
    }
}
