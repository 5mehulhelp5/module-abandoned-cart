<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Clears module-owned reminder capture from open carts once consent is withdrawn (switched off in the consent
 * source, "reject all", the unsubscribe link) or missing at capture. The cart's contact (captured reminder email)
 * is unlinked; Magento's checkout email is preserved. The browser binding goes with the consent (no consent,
 * nothing to restore). Sent/queued state stays, so opting in again cannot cause a second reminder. Only carts
 * with a consent are touched; Magento's quote row is not written.
 */
class ConsentWithdrawal
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CartTable $carts
    ) {
    }

    /** One cart (e.g. the visitor's own, when the capture finds no consent). */
    public function execute(int $quoteId, bool $guestOnly = false, ?int $storeId = null): bool
    {
        $where = ['q.entity_id = ?' => $quoteId];
        if ($storeId !== null) {
            $where['q.store_id = ?'] = $storeId;
        }
        return $quoteId && $this->clear($where, $guestOnly) > 0;
    }

    /** @param string[] $consentIds every open cart captured with one of these consents */
    public function forConsents(array $consentIds): int
    {
        return $consentIds ? $this->clear(['c.consent_id IN (?)' => array_values($consentIds)]) : 0;
    }

    private function clear(array $where, bool $guestOnly = false): int
    {
        // array_merge, not +: conditions without a placeholder have numeric keys, and + would drop them
        $where = array_merge($where, ['q.is_active = ?' => 1, 'c.consent_id IS NOT NULL']);
        $consent = ['c.contact_id' => null, 'c.captured_at' => null, 'c.consent_id' => null, 'c.browser_hash' => null];
        return $this->resource->getConnection()->update($this->carts->joined(), $consent,
            $guestOnly ? array_merge($where, ['q.customer_id IS NULL']) : $where);
    }
}
