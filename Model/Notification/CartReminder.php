<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model\Notification;

use CommerceKings\Notification\Api\SubscriptionRemovalInterface;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\QuietHoursInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Model\Config as NotificationConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterfaceFactory;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Notification type "cart_reminder" (CommerceKings_Notification's shared consumer). Reference = the cart's
 * subscription id (CartSubscription: who subscribed, under which permission); the send history is per cart.
 *
 * Messages can arrive twice or late, so everything is re-checked against the current cart:
 * still active (not ordered), has items, an email and a valid permission (Notification's
 * SubscriptionPermissionInterface; with the consent bridge: a consent that is not withdrawn, expired or from an
 * older revision, for this purpose and website), idle long enough, not expired under Magento's quote
 * lifetime and eligible under ReminderPolicyInterface (one reminder per cart by default).
 * The cart is then claimed with one atomic UPDATE that repeats the cart and permission checks - only one
 * delivery can win, and none after a withdrawal is stored. Mail already handed to the mail server can't
 * be recalled.
 * Sending (recipient cooldown, store emulation, one-click headers) is CommerceKings_Notification's sender and its selected provider.
 * A delivery version owns each claim. Failed sends restore the prior reminder timestamp only while that version
 * still matches, leaving a newer delivery or queue mark alone; the exception makes Magento log the failure.
 */
class CartReminder implements NotificationTypeInterface
{
    public const CODE = 'cart_reminder';
    private const HOURLY_LOCK = 'commercekings_abandoned_cart_hourly';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CartRepositoryInterfaceFactory $repositories,
        private readonly ReminderData $reminderData,
        private readonly SenderInterface $sender,
        private readonly Config $config,
        private readonly SubscriptionPermissionInterface $permission,
        private readonly ReminderPolicyInterface $policy,
        private readonly LoggerInterface $logger,
        private readonly QuoteLifetime $lifetime,
        private readonly ReminderEmailResolverInterface $recipients,
        private readonly NotificationConfig $notificationConfig,
        private readonly CartTable $carts,
        private readonly LockManagerInterface $locks,
        private readonly SubscriptionRemovalInterface $removal,
        private readonly CartSubscription $subscriptionType,
        private readonly QuietHoursInterface $quietHours
    ) {
    }

    public function process(string $reference): void
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($this->carts->select(['reminded_at', 'queued_at', 'delivery_version', 'failed_sends'],
            ['entity_id', 'is_active', 'items_count', 'customer_id', 'customer_email', 'store_id', 'updated_at'],
            ['subscription_id', 'contact_id', 'captured_at', 'permission_ref'])
            ->where('s.subscription_id = ?', (int)$reference));
        $id = $row ? (int)$row['entity_id'] : 0;
        $email = $row ? $this->recipients->resolve($id) : null;
        $activity = $row ? max((string)$row['updated_at'], (string)$row['captured_at']) : '';
        $skip = match (true) {
            !$row => 'subscription or cart gone',
            !(int)$row['is_active'] => 'ordered / closed',
            !(int)$row['items_count'] => 'empty',
            $email === null => 'no email',
            !$this->policy->canSend(new ReminderState($id, (int)$row['store_id'], $row['reminded_at'],
                ['updated_at' => $row['updated_at']])) => 'reminder policy',
            $this->lifetime->isExpired((string)$row['updated_at'], (int)$row['store_id']) => 'quote expired',
            $activity > gmdate('Y-m-d H:i:s', time() - $this->config->getDelayMinutes((int)$row['store_id']) * 60) => 'cart recently updated',
            !$this->permission->isValidFor((string)$row['permission_ref'], Config::PURPOSE, (int)$row['store_id'])
                => 'permission withdrawn, expired or for another purpose / website',
            !$this->config->isEnabled((int)$row['store_id']) => 'disabled',
            default => null,
        };
        $queuedAt = $row ? $row['queued_at'] : null;
        if ($skip !== null) {
            $this->release($id, $queuedAt);
            $this->logger->info($id ? sprintf('CommerceKings_AbandonedCart: cart %d skipped (%s)', $id, $skip)
                : sprintf('CommerceKings_AbandonedCart: subscription %d skipped (%s)', (int)$reference, $skip));
            return;
        }

        // claim: only one delivery of this message can flip reminded_at from NULL - and only while the cart
        // is still what was checked above and its permission is still valid, in the same statement: a
        // withdrawal stored before this point stops the send, one after it finds the reminder already out
        $delay = $this->config->getDelayMinutes((int)$row['store_id']);
        $previousVersion = (int)$row['delivery_version'];
        $deliveryVersion = $previousVersion + 1;
        $this->carts->ensure($id);
        $claim = fn (): int => $db->update(
            $this->carts->joined(),
            // sent: the cart no longer waits in the queue, so a recurring policy can schedule the next reminder
            ['c.reminded_at' => new Expression('NOW()'), 'c.queued_at' => null, 'c.delivery_version' => $deliveryVersion,
                'c.failed_sends' => 0],
            [
                'c.quote_id = ?' => $id,
                's.subscription_id = ?' => (int)$row['subscription_id'],
                'c.delivery_version = ?' => $previousVersion,
                'c.queued_at <=> ?' => $queuedAt ?? new Expression('NULL'),
                'q.store_id = ?' => (int)$row['store_id'],
                $this->policy->dueCondition(new ReminderQuery((int)$row['store_id'], 'c', 'q')),
                // Compare the state that was checked too, so parallel consumers cannot claim it twice.
                $row['reminded_at'] === null ? 'c.reminded_at IS NULL'
                    : $db->quoteInto('c.reminded_at = ?', $row['reminded_at']),
                'q.is_active = ?' => 1,
                'q.items_count > 0',
                'q.customer_id <=> ?' => $row['customer_id'] ?? new Expression('NULL'),
                'q.customer_email <=> ?' => $row['customer_email'] ?? new Expression('NULL'),
                's.contact_id <=> ?' => $row['contact_id'] ?? new Expression('NULL'),
                's.captured_at = ?' => (string)$row['captured_at'],
                's.permission_ref = ?' => (string)$row['permission_ref'],
                $this->lifetime->validCondition('q.updated_at', (int)$row['store_id']),
                'q.updated_at <= NOW() - INTERVAL ' . $delay . ' MINUTE',
                's.captured_at <= NOW() - INTERVAL ' . $delay . ' MINUTE',
                $this->permission->validCondition('s.permission_ref'),
            ]
        );
        $perHour = $this->config->getMaxPerHour();
        if ($perHour > 0) {
            // the provider's hourly limit holds when a delayed backlog drains: count and claim one at a time
            if (!$this->locks->lock(self::HOURLY_LOCK, 10)) {
                $this->release($id, $queuedAt);
                $this->logger->info(sprintf('CommerceKings_AbandonedCart: cart %d skipped (hourly limit busy)', $id));
                return;
            }
            try {
                $full = $this->sentLastHour() >= $perHour;
                $claimed = $full ? 0 : $claim();
            } finally {
                $this->locks->unlock(self::HOURLY_LOCK);
            }
            if ($full) {
                $this->release($id, $queuedAt);
                $this->logger->info(sprintf('CommerceKings_AbandonedCart: cart %d skipped (hourly limit)', $id));
                return;
            }
        } else {
            $claimed = $claim();
        }
        if (!$claimed) {
            // another delivery won, or the cart changed meanwhile; clear the mark this message read
            $this->release($id, $queuedAt);
            return;
        }
        $storeId = (int)$row['store_id'];
        // a send that did not happen (cooldown, block, changed recipient) keeps the failure count of earlier attempts
        $restore = fn () => $db->update($this->carts->name(),
            ['reminded_at' => $row['reminded_at'], 'failed_sends' => (int)($row['failed_sends'] ?? 0)],
            // Never undo a newer delivery or decrease the version; a newer queued mark must stay too.
            ['quote_id = ?' => $id, 'delivery_version = ?' => $deliveryVersion]);
        $message = new Delivery(
            $storeId,
            $this->config->getTemplate($storeId),
            $this->config->getIdentity($storeId),
            $email,
            function () use ($id, $row, $email, $storeId): DeliveryContent {
                // Consumers live across messages; a repository's cached quote must not survive a delivery attempt.
                $quote = $this->repositories->create()->get($id);
                if (!$quote instanceof Quote) {
                    throw new \UnexpectedValueException('Cart repository must return a Magento quote for a reminder.');
                }
                $vars = $this->reminderData->build($quote, (string)$row['permission_ref'],
                    ['subscription_id' => (int)$row['subscription_id'], 'email' => $email]);
                // one-click: the consent withdrawal link (opt-in); without one, Notification uses the stop link
                return new DeliveryContent($vars, $vars['customer_name'] ?? null,
                    ($vars['optout_url'] ?? '') !== '' && $this->notificationConfig->isOneClickUnsubscribeEnabled($storeId)
                        ? $vars['optout_url'] : null);
            },
            true,
            // A replaced resolver may read an external recipient source. Recheck it after claiming too.
            fn (): bool => $this->recipients->resolve($id) === $email,
            stoppable: $this->subscriptionType->isStoppable()
        );
        try {
            $status = $this->sender->send($message);
        } catch (MessageSentException $e) {
            // The provider accepted it. Keep the claim: a later cleanup error must not enable a duplicate.
            $this->logger->error(sprintf('CommerceKings_AbandonedCart: cart %d sent; post-send cleanup failed', $id),
                ['exception_class' => get_class($e->getPrevious() ?? $e)]);
            throw $e->getPrevious() ?? $e;
        } catch (\Throwable $e) {
            // Restore our own policy state; claiming already cleared queuing, and newer work must stay intact.
            // A send that keeps failing (a rejected address, a mail setting) waits longer each time, then stops.
            $failed = (int)($row['failed_sends'] ?? 0) + 1;
            $retry = CartTable::RETRY_MINUTES[$failed - 1] ?? null;
            $db->update($this->carts->name(), [
                'reminded_at' => $row['reminded_at'],
                'failed_sends' => $failed,
                'skip_until' => $retry !== null ? new Expression('NOW() + INTERVAL ' . $retry . ' MINUTE') : null,
            ], ['quote_id = ?' => $id, 'delivery_version = ?' => $deliveryVersion]);
            $this->logger->warning($retry !== null
                ? sprintf('CommerceKings_AbandonedCart: cart %d send failed (%d), next try in %d minutes', $id, $failed, $retry)
                : sprintf('CommerceKings_AbandonedCart: cart %d send failed %d times, no further reminder', $id, $failed));
            throw $e;
        }
        if ($status === SenderInterface::STOPPED) {
            // the address is on the do-not-contact list: this subscription ends here, so the finder never queues the
            // cart again (a "retry later" would re-queue it on every run until the block lifts)
            $this->removal->remove((int)$row['subscription_id']);
        }
        if ($status !== SenderInterface::SENT) {
            $restore();
            if ($status === SenderInterface::QUIET_HOURS) {
                // held, not dropped: the finder leaves the cart out until the quiet window ends, then queues it again
                $until = $this->quietHours->nextAllowedAt($storeId, $this->subscriptionType->getChannel());
                // null: the window ended since the send; the next run simply queues the cart again
                if ($until !== null) {
                    // UTC, like the finder's NOW() on Magento's database session
                    $db->update($this->carts->name(), ['skip_until' => gmdate('Y-m-d H:i:s', $until->getTimestamp())],
                        ['quote_id = ?' => $id, 'delivery_version = ?' => $deliveryVersion]);
                }
            }
            $this->logger->info(sprintf('CommerceKings_AbandonedCart: cart %d skipped (%s)', $id, $status));
            return;
        }
        $this->logger->info(sprintf('CommerceKings_AbandonedCart: reminder sent for cart %d', $id));
    }

    /**
     * The cart no longer waits for this message: clear its "queued" mark so the finder can find it again when it is
     * due. Only the mark this worker read is cleared; sending clears it too, so a cart sent since is left alone.
     * (Should a newer mark carry the same second, the worst case is one more message - the claim still sends once.)
     */
    private function release(int $id, ?string $queuedAt): void
    {
        if ($id && $queuedAt !== null) {
            // sending clears the mark, so an unchanged mark also proves nothing was sent since
            $this->resource->getConnection()->update($this->carts->name(), ['queued_at' => null],
                ['quote_id = ?' => $id, 'queued_at = ?' => $queuedAt]);
        }
    }

    /** Reminders sent (claimed) in the last hour, all stores; failed sends give theirs back. */
    private function sentLastHour(): int
    {
        $db = $this->resource->getConnection();
        return (int)$db->fetchOne($db->select()->from($this->carts->name(), new Expression('COUNT(*)'))
            ->where('reminded_at >= NOW() - INTERVAL 1 HOUR'));
    }
}
