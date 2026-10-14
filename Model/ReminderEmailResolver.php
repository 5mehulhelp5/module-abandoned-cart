<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/** Guests use their captured reminder email; customer carts use Magento's checkout email. */
class ReminderEmailResolver implements ReminderEmailResolverInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function resolve(int $quoteId): ?string
    {
        $db = $this->resource->getConnection();
        $email = $db->fetchOne($db->select()->from($this->resource->getTableName('quote'), [
            'email' => new Expression((string)$db->getCheckSql('customer_id IS NULL', 'ck_abandoned_email', 'customer_email')),
        ])->where('entity_id = ?', $quoteId));
        return $email ? (string)$email : null;
    }
}
