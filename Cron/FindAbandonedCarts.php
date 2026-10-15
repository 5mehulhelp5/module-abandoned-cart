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
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every 15 minutes: carts that have items and an email, were not ordered, have been untouched for
 * the configured delay, unexpired under Magento's quote lifetime and eligible under the reminder
 * policy -> one message per cart (its CommerceKings_Notification subscription id) to Notification's queue.
 * Only carts with a subscription whose consent is still valid qualify. The cart is marked "queued"
 * first, so the next run does not publish it again.
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
    private const BATCH = 200;
    private const STALE_MINUTES = 60;

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
        private readonly CartTable $carts
    ) {
    }

    public function execute(): int
    {
        if ($this->storeCondition() === '0 = 1') {
            return 0;
        }
        $db = $this->resource->getConnection();

        $released = $db->update(
            $this->carts->joined(),
            ['c.queued_at' => null],
            [
                'c.queued_at < NOW() - INTERVAL ' . self::STALE_MINUTES . ' MINUTE',
                $this->storeCondition(),
                'q.is_active = ?' => 1,
            ]
        );
        if ($released) {
            $this->logger->warning(sprintf('CommerceKings_AbandonedCart: released %d stale claim(s)', $released));
        }

        $due = $this->dueCarts();
        $published = 0;
        foreach ($due as $id => $subscriptionId) {
            // claim it first: only the run that flips queued_at from NULL publishes
            $this->carts->ensure($id);
            $claimed = $db->update(
                $this->carts->joined(),
                ['c.queued_at' => new Expression('NOW()')],
                array_merge(['c.quote_id = ?' => $id, 's.subscription_id = ?' => $subscriptionId],
                    $this->eligibleConditions())
            );
            if ($claimed) {
                $this->queue->publish(CartReminder::CODE, (string)$subscriptionId);
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
     * @return array<int, int> quote id => subscription id
     */
    private function dueCarts(): array
    {
        $db = $this->resource->getConnection();
        $select = $this->carts->select([], ['entity_id'], ['subscription_id']);
        foreach ($this->eligibleConditions() as $condition) {
            $select->where($condition);
        }
        $select->order(['q.updated_at ASC', 'q.entity_id ASC']);
        $due = [];
        $offset = 0;
        // Read pages before claiming, so carts without a resolvable email cannot block later carts.
        do {
            $rows = $db->fetchPairs((clone $select)->limit(self::BATCH, $offset));
            foreach ($rows as $id => $subscriptionId) {
                $email = $this->recipients->resolve((int)$id);
                if ($email !== null && !$this->cooldown->isRecent($email)) {
                    $due[(int)$id] = (int)$subscriptionId;
                    if (count($due) === self::BATCH) {
                        return $due;
                    }
                }
            }
            $offset += self::BATCH;
        } while (count($rows) === self::BATCH);
        return $due;
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
                $this->policy->dueCondition(new ReminderQuery($id, 'c'))
            );
        }
        return $branches ? '(' . implode(' OR ', $branches) . ')' : '0 = 1';
    }
}
