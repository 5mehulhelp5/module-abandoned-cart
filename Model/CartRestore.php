<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterfaceFactory;
use Magento\Quote\Model\Quote;

/** Preserve the destination cart and consume the source once, in one database transaction. */
class CartRestore
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CartRepositoryInterfaceFactory $repositories,
        private readonly RestoreAccess $access
    ) {
    }

    public function execute(int $sourceId, Quote $sessionQuote, string $token, int $customerId): Quote
    {
        $db = $this->resource->getConnection();
        $destinationId = (int)$sessionQuote->getId();
        $ids = array_values(array_unique(array_filter([$sourceId, $destinationId])));
        sort($ids, SORT_NUMERIC);
        $db->beginTransaction();
        try {
            // Lock in a stable order. A second restore must see the first one's closed source.
            $db->fetchAll($db->select()->from($this->resource->getTableName('quote'), 'entity_id')
                ->where('entity_id IN (?)', $ids)->order('entity_id')->forUpdate(true));
            // A new repository avoids quotes already cached by the checkout session or GET checks.
            $repository = $this->repositories->create();
            $source = $repository->get($sourceId);
            if (!$source instanceof Quote || !$this->access->isOwner($source, $token)
                || !$this->access->isAvailable($source) || $source->getCustomerId()
            ) {
                throw new LocalizedException(__('This cart is no longer available.'));
            }
            $destination = $destinationId ? $repository->get($destinationId) : $sessionQuote;
            if (!$destination instanceof Quote || ($destinationId && !$destination->getIsActive())
                || (int)$destination->getCustomerId() !== $customerId
                || (int)$destination->getStoreId() !== (int)$source->getStoreId()
            ) {
                throw new LocalizedException(__('Your current cart could not be restored. Please try again.'));
            }
            if ($sourceId === $destinationId || (!$destinationId && !$customerId)) {
                $db->commit();
                return $source;
            }
            $this->validateItems($source);
            $this->validateItems($destination);
            $coupon = $destination->getCouponCode();
            $destination->merge($source)->setIsActive(true)->setCouponCode($coupon)->setTotalsCollectedFlag(false);
            $destination->getShippingAddress()->setCollectShippingRates(true);
            $this->validateItems($destination);
            $repository->save($destination);
            $this->validateItems($destination);
            $source->setIsActive(false);
            $repository->save($source);
            $db->commit();
            return $destination;
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    private function validateItems(Quote $quote): void
    {
        foreach ($quote->getAllItems() as $item) {
            if ($item->isDeleted() || $item->getHasError() || !$item->getProduct()->isSalable()) {
                throw new LocalizedException(__('Some products are unavailable or their quantities cannot be added. Your current cart has been kept.'));
            }
        }
    }
}
