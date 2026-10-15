<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model\Notification;

use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Model\Config as ConsentConfig;
use CommerceKings\Notification\Api\Data\Message;
use CommerceKings\Notification\Api\Data\MessageContent;
use CommerceKings\Notification\Api\MailerInterface;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Notification type "cart_reminder" (CommerceKings_Notification's shared consumer). Reference = the cart's
 * subscription id (CartSubscription: who subscribed, with which consent); the send history is per cart.
 *
 * Messages can arrive twice or late, so everything is re-checked against the current cart:
 * still active (not ordered), has items, an email and a valid consent (CommerceKings_Consent: not
 * withdrawn, expired or from an older revision), idle long enough, not expired under Magento's quote
 * lifetime and eligible under ReminderPolicyInterface (one reminder per cart by default).
 * The cart is then claimed with one atomic UPDATE that repeats the cart and consent checks - only one
 * delivery can win, and none after a withdrawal is stored. Mail already handed to the mail server can't
 * be recalled.
 * Sending (recipient cooldown, store emulation, one-click headers) is CommerceKings_Notification's Mailer.
 * If sending fails the claim is released (queued_at too), so the next cron run queues it again;
 * the exception makes Magento log the failure.
 */
class CartReminder implements NotificationTypeInterface
{
    public const CODE = 'cart_reminder';
    private const HOURLY_LOCK = 'commercekings_abandoned_cart_hourly';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ReminderData $reminderData,
        private readonly MailerInterface $mailer,
        private readonly Config $config,
        private readonly ConsentInterface $consent,
        private readonly ConsentGuardInterface $guard,
        private readonly ReminderPolicyInterface $policy,
        private readonly LoggerInterface $logger,
        private readonly QuoteScope $scope,
        private readonly QuoteLifetime $lifetime,
        private readonly ReminderEmailResolverInterface $recipients,
        private readonly ConsentConfig $consentConfig,
        private readonly CartTable $carts,
        private readonly LockManagerInterface $locks
    ) {
    }

    public function process(string $reference): void
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($this->carts->select(['reminded_at', 'queued_at'],
            ['entity_id', 'is_active', 'items_count', 'customer_id', 'customer_email', 'store_id', 'updated_at'],
            ['subscription_id', 'contact_id', 'captured_at', 'consent_id'])
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
            !$this->consent->isValid((string)$row['consent_id']) => 'consent withdrawn / expired',
            !$this->matchesConsent($row) => 'consent purpose / website mismatch',
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
        // is still what was checked above and its consent is still valid, in the same statement: a
        // withdrawal stored before this point stops the send, one after it finds the reminder already out
        $delay = $this->config->getDelayMinutes((int)$row['store_id']);
        $this->carts->ensure($id);
        $claim = fn (): int => $db->update(
            $this->carts->joined(),
            // sent: the cart no longer waits in the queue, so a recurring policy can schedule the next reminder
            ['c.reminded_at' => new Expression('NOW()'), 'c.queued_at' => null],
            [
                'c.quote_id = ?' => $id,
                's.subscription_id = ?' => (int)$row['subscription_id'],
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
                's.consent_id = ?' => (string)$row['consent_id'],
                $this->lifetime->validCondition('q.updated_at', (int)$row['store_id']),
                'q.updated_at <= NOW() - INTERVAL ' . $delay . ' MINUTE',
                's.captured_at <= NOW() - INTERVAL ' . $delay . ' MINUTE',
                $this->guard->validCondition('s.consent_id'),
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
            // another delivery won, or the cart changed meanwhile; free this message's reservation
            $this->release($id, $queuedAt);
            return;
        }
        $storeId = (int)$row['store_id'];
        $restore = fn () => $db->update($this->carts->name(),
            ['reminded_at' => $row['reminded_at'], 'queued_at' => null],
            // this claim cleared the mark; a newer mark means the cart is queued again - leave that alone
            ['quote_id = ?' => $id, 'queued_at IS NULL']);
        $message = new Message(
            $storeId,
            $this->config->getTemplate($storeId),
            $this->config->getIdentity($storeId),
            $email,
            function () use ($id, $row): MessageContent {
                $quote = $this->cartRepository->get($id);
                if (!$quote instanceof Quote) {
                    throw new \UnexpectedValueException('Cart repository must return a Magento quote for a reminder.');
                }
                $vars = $this->reminderData->build($quote, (string)$row['consent_id']);
                return new MessageContent($vars, $vars['customer_name'] ?? null,
                    $this->consentConfig->isOneClickUnsubscribeEnabled() ? $vars['optout_url'] : null);
            },
            true,
            // A replaced resolver may read an external recipient source. Recheck it after claiming too.
            fn (): bool => $this->recipients->resolve($id) === $email
        );
        try {
            $status = $this->mailer->send($message);
        } catch (MessageSentException $e) {
            // SMTP returned successfully. Keep the claim: a later cleanup error must not enable a duplicate.
            $this->logger->error(sprintf('CommerceKings_AbandonedCart: cart %d sent; post-send cleanup failed', $id),
                ['exception_class' => get_class($e->getPrevious() ?? $e)]);
            throw $e->getPrevious() ?? $e;
        } catch (\Throwable $e) {
            // Restore the previous policy state and release queuing so the next cron run can retry.
            $restore();
            throw $e;
        }
        if ($status !== MailerInterface::SENT) {
            $restore();
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

    private function matchesConsent(array $row): bool
    {
        $consent = $this->consent->get((string)$row['consent_id']);
        return $consent !== null && $this->scope->matches($consent, (int)$row['store_id']);
    }
}
