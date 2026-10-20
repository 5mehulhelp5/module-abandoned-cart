<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model\Notification;

use CommerceKings\Notification\Api\SubscriptionRemovalInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderMessage;
use CommerceKings\AbandonedCart\Api\ReminderTypeInterface;
use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\QuietHoursInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterfaceFactory;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * A cart reminder of one ReminderTypeInterface: the email reminder "cart_reminder" by default, another channel's
 * through a virtual type with its "type" argument (see docs/extending.md). Notification type for
 * CommerceKings_Notification's shared consumer; reference = the cart's subscription id of that type (CartSubscription:
 * who subscribed, under which permission); the send history is per cart and type.
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
 * still matches, leaving a newer delivery or queue mark alone; the exception makes Magento log the failure. A
 * temporary refusal (TemporaryFailureException) gives the claim back the same way and waits a short while
 * (Retry-After, else 15 minutes) without counting as a failed send or being rethrown.
 *
 * @api a virtual type with its "type" argument (ReminderTypeInterface) is a stable extension point
 */
class CartReminder implements NotificationTypeInterface
{
    /** The built-in email reminder's code. */
    public const CODE = 'cart_reminder';
    /** The email reminder's hourly lock; another type's adds "_<code>" (its own provider, its own limit). */
    public const HOURLY_LOCK = 'commercekings_abandoned_cart_hourly';
    /** A temporary refusal (TemporaryFailureException) waits this long, or the provider's Retry-After within the bounds. */
    public const TEMPORARY_RETRY_SECONDS = 900;
    public const TEMPORARY_MIN_SECONDS = 60;
    public const TEMPORARY_MAX_SECONDS = 86400;

    private readonly CartTable $carts;
    /** The type's own permission, else the shop-wide one - as Notification resolves it for the subscription type. */
    private readonly SubscriptionPermissionInterface $permission;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CartRepositoryInterfaceFactory $repositories,
        private readonly SenderInterface $sender,
        SubscriptionPermissionInterface $shopWidePermission,
        private readonly LoggerInterface $logger,
        private readonly QuoteLifetime $lifetime,
        CartTable $carts,
        private readonly LockManagerInterface $locks,
        private readonly SubscriptionRemovalInterface $removal,
        private readonly QuietHoursInterface $quietHours,
        private readonly ReminderTypeInterface $type
    ) {
        $this->carts = $carts->forType($type->getCode());
        $this->permission = $type->getPermission() ?? $shopWidePermission;
    }

    /** The lock for one type's hourly limit; the email reminder keeps its own name. */
    public static function hourlyLock(string $typeCode): string
    {
        return $typeCode === self::CODE ? self::HOURLY_LOCK : self::HOURLY_LOCK . '_' . $typeCode;
    }

    public function process(string $reference): void
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($this->carts->select(['sent_at', 'queued_at', 'delivery_version', 'failed_sends', 'skip_until'],
            ['entity_id', 'is_active', 'items_count', 'customer_id', 'customer_email', 'store_id', 'updated_at'],
            ['subscription_id', 'contact_id', 'captured_at', 'permission_ref'])
            ->where('s.subscription_id = ?', (int)$reference));
        $id = $row ? (int)$row['entity_id'] : 0;
        $email = $row ? $this->type->getRecipient($id) : null;
        $activity = $row ? max((string)$row['updated_at'], (string)$row['captured_at']) : '';
        $skip = match (true) {
            !$row => 'subscription or cart gone',
            !(int)$row['is_active'] => 'ordered / closed',
            !(int)$row['items_count'] => 'empty',
            $email === null => $this->type->getChannel() === Channel::EMAIL ? 'no email' : 'no recipient',
            !$this->type->getPolicy()->canSend(new ReminderState($id, (int)$row['store_id'], $row['sent_at'],
                ['updated_at' => $row['updated_at']])) => 'reminder policy',
            $this->lifetime->isExpired((string)$row['updated_at'], (int)$row['store_id']) => 'quote expired',
            $activity > gmdate('Y-m-d H:i:s', time() - $this->type->getDelayMinutes((int)$row['store_id']) * 60) => 'cart recently updated',
            !$this->permission->isValidFor((string)$row['permission_ref'], $this->type->getPurpose(), (int)$row['store_id'])
                => 'permission withdrawn, expired or for another purpose / website',
            !$this->type->isEnabled((int)$row['store_id']) => 'disabled',
            // the finder's retry state: a duplicate or redelivered message neither cuts a backoff short nor tries
            // again after the last retry (FindAbandonedCarts queues only what passes these)
            (int)($row['failed_sends'] ?? 0) > count(CartTable::RETRY_MINUTES) => 'given up after failed sends',
            ($row['skip_until'] ?? null) !== null && (string)$row['skip_until'] > gmdate('Y-m-d H:i:s')
                => 'waiting to retry',
            default => null,
        };
        $queuedAt = $row ? $row['queued_at'] : null;
        if ($skip !== null) {
            $this->release($id, $queuedAt);
            $this->logger->info($id ? sprintf('CommerceKings_AbandonedCart: cart %d skipped (%s)', $id, $skip)
                : sprintf('CommerceKings_AbandonedCart: subscription %d skipped (%s)', (int)$reference, $skip));
            return;
        }

        // claim: only one delivery of this message can flip sent_at from NULL - and only while the cart
        // is still what was checked above and its permission is still valid, in the same statement: a
        // withdrawal stored before this point stops the send, one after it finds the reminder already out
        $delay = $this->type->getDelayMinutes((int)$row['store_id']);
        $previousVersion = (int)$row['delivery_version'];
        $deliveryVersion = $previousVersion + 1;
        $this->carts->ensure($id);
        $claim = fn (): int => $db->update(
            $this->carts->joined(),
            // sent: the cart no longer waits in the queue, so a recurring policy can schedule the next reminder
            ['c.sent_at' => new Expression('NOW()'), 'c.queued_at' => null, 'c.delivery_version' => $deliveryVersion,
                'c.failed_sends' => 0],
            $this->carts->key($id, 'c') + [
                's.subscription_id = ?' => (int)$row['subscription_id'],
                'c.delivery_version = ?' => $previousVersion,
                'c.queued_at <=> ?' => $queuedAt ?? new Expression('NULL'),
                'q.store_id = ?' => (int)$row['store_id'],
                $this->type->getPolicy()->dueCondition(new ReminderQuery((int)$row['store_id'], 'c', 'q')),
                // Compare the state that was checked too, so parallel consumers cannot claim it twice.
                $row['sent_at'] === null ? 'c.sent_at IS NULL'
                    : $db->quoteInto('c.sent_at = ?', $row['sent_at']),
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
                // the retry state too, as the finder reads it: a duplicate can't claim during a backoff
                '(c.skip_until IS NULL OR c.skip_until <= NOW())',
                '(c.failed_sends IS NULL OR c.failed_sends <= ' . count(CartTable::RETRY_MINUTES) . ')',
            ]
        );
        $perHour = $this->type->getMaxPerHour();
        if ($perHour > 0) {
            // the provider's hourly limit holds when a delayed backlog drains: count and claim one at a time
            if (!$this->locks->lock(self::hourlyLock($this->type->getCode()), 10)) {
                $this->release($id, $queuedAt);
                $this->logger->info(sprintf('CommerceKings_AbandonedCart: cart %d skipped (hourly limit busy)', $id));
                return;
            }
            try {
                $full = $this->sentLastHour() >= $perHour;
                $claimed = $full ? 0 : $claim();
            } finally {
                $this->locks->unlock(self::hourlyLock($this->type->getCode()));
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
        // a send that did not happen (cooldown, stop, changed recipient) keeps the failure count of earlier attempts
        $restore = fn () => $db->update($this->carts->name(),
            ['sent_at' => $row['sent_at'], 'failed_sends' => (int)($row['failed_sends'] ?? 0)],
            // Never undo a newer delivery or decrease the version; a newer queued mark must stay too.
            $this->carts->key($id) + ['delivery_version = ?' => $deliveryVersion]);
        $message = $this->type->createDelivery(new ReminderMessage(
            $id,
            $storeId,
            (int)$row['subscription_id'],
            $email,
            (string)$row['permission_ref'],
            function () use ($id): Quote {
                // Consumers live across messages; a repository's cached quote must not survive a delivery attempt.
                $quote = $this->repositories->create()->get($id);
                if (!$quote instanceof Quote) {
                    throw new \UnexpectedValueException('Cart repository must return a Magento quote for a reminder.');
                }
                return $quote;
            },
            // A replaced resolver may read an external recipient source. Recheck it after claiming too.
            fn (): bool => $this->type->getRecipient($id) === $email
        ));
        try {
            $status = $this->sender->send($message);
        } catch (MessageSentException $e) {
            // The provider accepted it. Keep the claim: a later cleanup error must not enable a duplicate.
            $this->logger->error(sprintf('CommerceKings_AbandonedCart: cart %d sent; post-send cleanup failed', $id),
                ['exception_class' => get_class($e->getPrevious() ?? $e)]);
            throw $e->getPrevious() ?? $e;
        } catch (TemporaryFailureException $e) {
            // Refused for now (a busy or throttling mail server, a rate limit): nothing was sent. Give the claim back
            // and try again soon - not an attempt that counts towards giving up.
            $wait = min(self::TEMPORARY_MAX_SECONDS, max(self::TEMPORARY_MIN_SECONDS,
                $e->getRetryAfter() ?? self::TEMPORARY_RETRY_SECONDS));
            $db->update($this->carts->name(), [
                'sent_at' => $row['sent_at'],
                'failed_sends' => (int)($row['failed_sends'] ?? 0),
                'skip_until' => new Expression('NOW() + INTERVAL ' . $wait . ' SECOND'),
            ], $this->carts->key($id) + ['delivery_version = ?' => $deliveryVersion]);
            $this->logger->warning(sprintf('CommerceKings_AbandonedCart: cart %d not sent for now (%s), next try in %d'
                . ' seconds', $id, $e->getMessage(), $wait));
            return;
        } catch (\Throwable $e) {
            // Restore our own policy state; claiming already cleared queuing, and newer work must stay intact.
            // A send that keeps failing (a rejected address, a mail setting) waits longer each time, then stops.
            $failed = (int)($row['failed_sends'] ?? 0) + 1;
            $retry = CartTable::RETRY_MINUTES[$failed - 1] ?? null;
            $db->update($this->carts->name(), [
                'sent_at' => $row['sent_at'],
                'failed_sends' => $failed,
                'skip_until' => $retry !== null ? new Expression('NOW() + INTERVAL ' . $retry . ' MINUTE') : null,
            ], $this->carts->key($id) + ['delivery_version = ?' => $deliveryVersion]);
            $this->logger->warning($retry !== null
                ? sprintf('CommerceKings_AbandonedCart: cart %d send failed (%d), next try in %d minutes', $id, $failed, $retry)
                : sprintf('CommerceKings_AbandonedCart: cart %d send failed %d times, no further reminder', $id, $failed));
            throw $e;
        }
        if ($status === SenderInterface::STOPPED) {
            // the address is on the do-not-contact list: this subscription ends here, so the finder never queues the
            // cart again (a "retry later" would re-queue it on every run until the stop lifts)
            $this->removal->remove((int)$row['subscription_id']);
        }
        if ($status !== SenderInterface::SENT) {
            $restore();
            if ($status === SenderInterface::QUIET_HOURS) {
                // held, not dropped: the finder leaves the cart out until the quiet window ends, then queues it again
                $until = $this->quietHours->nextAllowedAt($storeId, $this->type->getChannel(), $email);
                // null: the window ended since the send; the next run simply queues the cart again
                if ($until !== null) {
                    // UTC, like the finder's NOW() on Magento's database session
                    $db->update($this->carts->name(), ['skip_until' => gmdate('Y-m-d H:i:s', $until->getTimestamp())],
                        $this->carts->key($id) + ['delivery_version = ?' => $deliveryVersion]);
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
                $this->carts->key($id) + ['queued_at = ?' => $queuedAt]);
        }
    }

    /** Reminders sent (claimed) in the last hour, all stores; failed sends give theirs back. */
    private function sentLastHour(): int
    {
        $db = $this->resource->getConnection();
        return (int)$db->fetchOne($db->select()->from($this->carts->name(), new Expression('COUNT(*)'))
            ->where('type = ?', $this->type->getCode())->where('sent_at >= NOW() - INTERVAL 1 HOUR'));
    }
}
