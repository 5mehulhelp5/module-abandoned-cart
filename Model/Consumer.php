<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer "commerceKingsAbandonedCartRemind". Message = quote id.
 *
 * Messages can arrive twice or late, so everything is re-checked against the current cart:
 * still active (not ordered), has items, an email and a valid consent (CommerceKings_Consent: not
 * withdrawn, expired or from an older revision), idle long enough, not expired under Magento's quote
 * lifetime and eligible under ReminderPolicyInterface (one reminder per cart by default).
 * The cart is then claimed with one atomic UPDATE that repeats the cart and consent checks - only one
 * delivery can win, and none after a withdrawal is stored. Mail already handed to the mail server can't
 * be recalled.
 * If sending fails the claim is released (queued_at too), so the next cron run queues it again;
 * the exception makes Magento log the failure.
 */
class Consumer
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ReminderData $reminderData,
        private readonly TransportBuilder $transportBuilder,
        private readonly Emulation $emulation,
        private readonly Config $config,
        private readonly ConsentInterface $consent,
        private readonly ConsentGuardInterface $guard,
        private readonly ReminderPolicyInterface $policy,
        private readonly LoggerInterface $logger,
        private readonly QuoteScope $scope,
        private readonly QuoteLifetime $lifetime,
        private readonly ReminderEmailResolverInterface $recipients
    ) {
    }

    public function process(string $quoteId): void
    {
        $id = (int)$quoteId;
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('quote');
        $row = $db->fetchRow(
            $db->select()->from($table, ['is_active', 'items_count', 'customer_id', 'customer_email', 'ck_abandoned_email',
                'ck_abandoned_email_updated_at', 'store_id', 'updated_at', 'ck_abandoned_reminded_at', 'ck_abandoned_consent_id'])
                ->where('entity_id = ?', $id)
        );
        $email = $row ? $this->recipients->resolve($id) : null;
        $activity = $row ? max((string)$row['updated_at'], (string)($row['customer_id'] === null
            ? $row['ck_abandoned_email_updated_at'] : null)) : '';
        $skip = match (true) {
            !$row => 'cart gone',
            !(int)$row['is_active'] => 'ordered / closed',
            !(int)$row['items_count'] => 'empty',
            $email === null => 'no email',
            $row['ck_abandoned_consent_id'] === null => 'no consent',
            !$this->policy->canSend(new ReminderState($id, (int)$row['store_id'], $row['ck_abandoned_reminded_at'],
                ['updated_at' => $row['updated_at']])) => 'reminder policy',
            $this->lifetime->isExpired((string)$row['updated_at'], (int)$row['store_id']) => 'quote expired',
            $activity > gmdate('Y-m-d H:i:s', time() - $this->config->getDelayMinutes((int)$row['store_id']) * 60) => 'cart recently updated',
            !$this->consent->isValid((string)$row['ck_abandoned_consent_id']) => 'consent withdrawn / expired',
            !$this->matchesConsent($row) => 'consent purpose / website mismatch',
            !$this->config->isEnabled((int)$row['store_id']) => 'disabled',
            default => null,
        };
        if ($skip !== null) {
            $this->logger->info(sprintf('CommerceKings_AbandonedCart: cart %d skipped (%s)', $id, $skip));
            return;
        }

        // claim: only one delivery of this message can flip reminded_at from NULL - and only while the cart
        // is still what was checked above and its consent is still valid, in the same statement: a
        // withdrawal stored before this point stops the send, one after it finds the reminder already out
        $keepUpdatedAt = ['updated_at' => new Expression('updated_at')];
        $claimed = $db->update(
            $table,
            ['ck_abandoned_reminded_at' => new Expression('NOW()')] + $keepUpdatedAt,
            [
                'entity_id = ?' => $id,
                'store_id = ?' => (int)$row['store_id'],
                $this->policy->dueCondition(new ReminderQuery((int)$row['store_id'])),
                // Compare the state that was checked too, so parallel consumers cannot claim it twice.
                $row['ck_abandoned_reminded_at'] === null ? 'ck_abandoned_reminded_at IS NULL'
                    : $db->quoteInto('ck_abandoned_reminded_at = ?', $row['ck_abandoned_reminded_at']),
                'is_active = ?' => 1,
                'items_count > 0',
                'customer_id <=> ?' => $row['customer_id'] ?? new Expression('NULL'),
                'customer_email <=> ?' => $row['customer_email'] ?? new Expression('NULL'),
                'ck_abandoned_email <=> ?' => $row['ck_abandoned_email'] ?? new Expression('NULL'),
                'ck_abandoned_email_updated_at <=> ?' => $row['ck_abandoned_email_updated_at'] ?? new Expression('NULL'),
                'ck_abandoned_consent_id = ?' => (string)$row['ck_abandoned_consent_id'],
                $this->lifetime->validCondition('updated_at', (int)$row['store_id']),
                'updated_at <= NOW() - INTERVAL ' . $this->config->getDelayMinutes((int)$row['store_id']) . ' MINUTE',
                '(customer_id IS NOT NULL OR ck_abandoned_email_updated_at IS NULL OR ck_abandoned_email_updated_at <= NOW() - INTERVAL '
                    . $this->config->getDelayMinutes((int)$row['store_id']) . ' MINUTE)',
                $this->guard->validCondition('ck_abandoned_consent_id'),
            ]
        );
        if (!$claimed) {
            return;
        }
        $storeId = (int)$row['store_id'];
        $sent = false;
        try {
            // A replaced resolver may read an external recipient source. Recheck it after claiming too.
            if ($this->recipients->resolve($id) !== $email) {
                $db->update($table, ['ck_abandoned_reminded_at' => $row['ck_abandoned_reminded_at'],
                    'ck_abandoned_queued_at' => null] + $keepUpdatedAt, ['entity_id = ?' => $id]);
                $this->logger->info(sprintf('CommerceKings_AbandonedCart: cart %d skipped (recipient changed)', $id));
                return;
            }
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            try {
                $quote = $this->cartRepository->get($id);
                if (!$quote instanceof Quote) {
                    throw new \UnexpectedValueException('Cart repository must return a Magento quote for a reminder.');
                }
                $vars = $this->reminderData->build($quote);
                $this->transportBuilder
                    ->setTemplateIdentifier($this->config->getTemplate($storeId))
                    ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                    ->setTemplateVars($vars)
                    ->setFromByScope($this->config->getIdentity($storeId), $storeId)
                    ->addTo($email, $vars['customer_name'] ?: null)
                    ->getTransport()
                    ->sendMessage();
                $sent = true;
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        } catch (\Throwable $e) {
            if ($sent) {
                // SMTP returned successfully. A later emulation cleanup error must not enable a duplicate.
                $this->logger->error(sprintf('CommerceKings_AbandonedCart: cart %d sent; post-send cleanup failed', $id),
                    ['exception_class' => get_class($e)]);
                throw $e;
            }
            // Restore the previous policy state and release queuing so the next cron run can retry.
            $db->update(
                $table,
                ['ck_abandoned_reminded_at' => $row['ck_abandoned_reminded_at'], 'ck_abandoned_queued_at' => null] + $keepUpdatedAt,
                ['entity_id = ?' => $id]
            );
            throw $e;
        }
        $this->logger->info(sprintf('CommerceKings_AbandonedCart: reminder sent for cart %d', $id));
    }

    private function matchesConsent(array $row): bool
    {
        $consent = $this->consent->get((string)$row['ck_abandoned_consent_id']);
        return $consent !== null && $this->scope->matches($consent, (int)$row['store_id']);
    }
}
