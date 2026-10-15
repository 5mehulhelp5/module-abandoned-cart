<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/** Guests use their subscribed email (Notification contact); customer carts use Magento's checkout email. */
class ReminderEmailResolver implements ReminderEmailResolverInterface
{
    public function __construct(private readonly ResourceConnection $resource, private readonly CartTable $carts)
    {
    }

    public function resolve(int $quoteId): ?string
    {
        $db = $this->resource->getConnection();
        $email = $db->fetchOne($this->carts->select([], [
            'email' => new Expression((string)$db->getCheckSql('q.customer_id IS NULL', 'n.email', 'q.customer_email')),
        ])->where('q.entity_id = ?', $quoteId));
        return $email ? (string)$email : null;
    }
}
