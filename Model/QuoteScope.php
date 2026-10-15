<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Consent\Api\Data\Consent;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/** Authorize capture against the current website before reading consent or changing the cart's reminder state. */
class QuoteScope
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $stores
    ) {
    }

    public function find(int $quoteId, int $customerId = 0): ?array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from(['q' => $this->resource->getTableName('quote')], ['store_id'])
            ->joinInner(['s' => $this->resource->getTableName('store')], 's.store_id = q.store_id', [])
            ->where('q.entity_id = ?', $quoteId)->where('q.is_active = ?', 1)
            ->where('s.website_id = ?', (int)$this->stores->getStore()->getWebsiteId());
        $customerId ? $select->where('q.customer_id = ?', $customerId) : $select->where('q.customer_id IS NULL');
        return $db->fetchRow($select) ?: null;
    }

    public function matches(Consent $consent, int $storeId): bool
    {
        return $consent->purpose === Config::PURPOSE
            && $consent->websiteId === (int)$this->stores->getStore($storeId)->getWebsiteId();
    }
}
