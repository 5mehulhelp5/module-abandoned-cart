<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Notification\Api\ContactReferenceInterface;
use Magento\Framework\App\ResourceConnection;

/** A contact stays while a cart's reminder state points to it. */
class ContactReference implements ContactReferenceInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function usedCondition(string $contactIdColumn): string
    {
        return sprintf('EXISTS (SELECT 1 FROM %s AS ck_ac WHERE ck_ac.contact_id = %s)',
            $this->resource->getConnection()->quoteIdentifier($this->resource->getTableName(CartTable::TABLE)),
            $contactIdColumn);
    }
}
