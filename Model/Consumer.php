<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer "commerceKingsAbandonedCartRemind". Message = quote id.
 *
 * Messages can arrive twice or late, so everything is re-checked against the current cart:
 * still active (not ordered), has items, an email and a valid consent (CommerceKings_Consent: not
 * withdrawn, expired or from an older revision), not reminded yet, and the address got no reminder within
 * Config::RECIPIENT_COOLDOWN_DAYS (RecipientHistory - kept apart from the carts, which Magento may delete).
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
        private readonly RecipientHistory $recipients,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $quoteId): void
    {
        $id = (int)$quoteId;
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('quote');
        $row = $db->fetchRow(
            $db->select()->from($table, ['is_active', 'items_count', 'customer_email', 'store_id', 'ck_abandoned_reminded_at', 'ck_abandoned_consent_id'])
                ->where('entity_id = ?', $id)
        );
        $skip = match (true) {
            !$row => 'cart gone',
            !(int)$row['is_active'] => 'ordered / closed',
            !(int)$row['items_count'] => 'empty',
            !$row['customer_email'] => 'no email',
            $row['ck_abandoned_consent_id'] === null => 'no consent',
            $row['ck_abandoned_reminded_at'] !== null => 'already reminded',
            !$this->consent->isValid((string)$row['ck_abandoned_consent_id']) => 'consent withdrawn / expired',
            $this->recipients->isRecent((string)$row['customer_email']) => 'address reminded recently',
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
        $keepUpdatedAt = ['updated_at' => new \Zend_Db_Expr('updated_at')];
        $claimed = $db->update(
            $table,
            ['ck_abandoned_reminded_at' => new \Zend_Db_Expr('NOW()')] + $keepUpdatedAt,
            [
                'entity_id = ?' => $id,
                'ck_abandoned_reminded_at IS NULL',
                'is_active = ?' => 1,
                'items_count > 0',
                'customer_email = ?' => (string)$row['customer_email'],
                'ck_abandoned_consent_id = ?' => (string)$row['ck_abandoned_consent_id'],
                $this->guard->validCondition('ck_abandoned_consent_id'),
            ]
        );
        if (!$claimed) {
            return;
        }
        $email = (string)$row['customer_email'];
        $storeId = (int)$row['store_id'];
        $addressClaimed = false;
        try {
            // and the address: only one reminder per cooldown, also across consumers running in parallel
            if (!$this->recipients->claim($email)) {
                $db->update($table, ['ck_abandoned_reminded_at' => null] + $keepUpdatedAt, ['entity_id = ?' => $id]);
                $this->logger->info(sprintf('CommerceKings_AbandonedCart: cart %d skipped (address reminded recently)', $id));
                return;
            }
            $addressClaimed = true;

            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            try {
                $quote = $this->cartRepository->get($id);
                $vars = $this->reminderData->build($quote);
                $this->transportBuilder
                    ->setTemplateIdentifier($this->config->getTemplate($storeId))
                    ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                    ->setTemplateVars($vars)
                    ->setFromByScope($this->config->getIdentity($storeId), $storeId)
                    ->addTo($email, $vars['customer_name'] ?: null)
                    ->getTransport()
                    ->sendMessage();
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        } catch (\Throwable $e) {
            // nothing was sent: release the cart so the next cron run queues it again, and the
            // address only if this attempt claimed it (otherwise it belongs to another reminder)
            $db->update(
                $table,
                ['ck_abandoned_reminded_at' => null, 'ck_abandoned_queued_at' => null] + $keepUpdatedAt,
                ['entity_id = ?' => $id]
            );
            if ($addressClaimed) {
                $this->recipients->release($email);
            }
            throw $e;
        }
        $this->logger->info(sprintf('CommerceKings_AbandonedCart: reminder sent for cart %d', $id));
    }
}
