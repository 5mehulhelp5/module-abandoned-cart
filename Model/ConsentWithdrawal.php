<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Clears the reminder consent from open carts once it is withdrawn (switched off in the consent
 * source, "reject all", the unsubscribe link) or missing at capture. Guest carts: the email goes too
 * (this module stored it). Customer carts: only the consent - the email belongs to the account and
 * Magento needs it. The browser binding goes with the consent (no consent, nothing to restore). Only
 * carts with a consent are touched. Direct UPDATEs - no quote save, updated_at kept.
 */
class ConsentWithdrawal
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /** One cart (e.g. the visitor's own, when the capture finds no consent). */
    public function execute(int $quoteId, bool $guestOnly = false, ?int $storeId = null): bool
    {
        $where = ['entity_id = ?' => $quoteId];
        if ($storeId !== null) {
            $where['store_id = ?'] = $storeId;
        }
        return $quoteId && $this->clear($where, $guestOnly) > 0;
    }

    /** @param string[] $consentIds every open cart captured with one of these consents */
    public function forConsents(array $consentIds): int
    {
        return $consentIds ? $this->clear(['ck_abandoned_consent_id IN (?)' => array_values($consentIds)]) : 0;
    }

    private function clear(array $where, bool $guestOnly = false): int
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('quote');
        // array_merge, not +: conditions without a placeholder have numeric keys, and + would drop them
        $where = array_merge($where, ['is_active = ?' => 1, 'ck_abandoned_consent_id IS NOT NULL']);
        $consent = [
            'ck_abandoned_consent_id' => null,
            'ck_abandoned_browser_hash' => null,
            'updated_at' => new Expression('updated_at'),
        ];
        $guests = $db->update($table, ['customer_email' => null] + $consent, array_merge($where, ['customer_id IS NULL']));
        return $guestOnly ? $guests
            : $guests + $db->update($table, $consent, array_merge($where, ['customer_id IS NOT NULL']));
    }
}
