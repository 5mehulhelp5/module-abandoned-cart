<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\SubscriptionManagement;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

/**
 * The module's send history (ck_abandoned_cart, alias "c") with Magento's cart (quote, alias "q") and the cart's
 * CommerceKings_Notification subscription (alias "s": contact, consent, browser, captured_at).
 * joined() is a table expression for the adapter's update(): one atomic UPDATE can still check the cart, the
 * subscription's consent and the send history together.
 */
class CartTable
{
    public const TABLE = 'ck_abandoned_cart';
    /** Delay before the next try after the 1st, 2nd, ... failed send; after the last one the cart is given up. */
    public const RETRY_MINUTES = [60, 240, 1440, 1440];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function name(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /** "ck_abandoned_cart AS c INNER JOIN quote AS q … INNER JOIN ck_notification_subscription AS s …" for UPDATEs. */
    public function joined(): Expression
    {
        $db = $this->resource->getConnection();
        return new Expression(sprintf('%s AS c INNER JOIN %s AS q ON q.entity_id = c.quote_id INNER JOIN %s AS s ON %s',
            $db->quoteIdentifier($this->name()), $db->quoteIdentifier($this->resource->getTableName('quote')),
            $db->quoteIdentifier($this->resource->getTableName(SubscriptionManagement::TABLE)), $this->subscribed('c.quote_id')));
    }

    /**
     * SELECT … FROM quote q JOIN its subscription s, with the send history c (no row yet = never queued) and
     * the guest contact n.
     */
    public function select(array $cartColumns = [], array $quoteColumns = [], array $subscriptionColumns = []): Select
    {
        return $this->resource->getConnection()->select()
            ->from(['q' => $this->resource->getTableName('quote')], $quoteColumns)
            ->join(['s' => $this->resource->getTableName(SubscriptionManagement::TABLE)], $this->subscribed('q.entity_id'),
                $subscriptionColumns)
            ->joinLeft(['c' => $this->name()], 'c.quote_id = q.entity_id', $cartColumns)
            ->joinLeft(['n' => $this->resource->getTableName(ContactRepository::TABLE)], 'n.contact_id = s.contact_id', []);
    }

    /** Create the cart's send history row before its first conditional update; an existing row is kept. */
    public function ensure(int $quoteId): void
    {
        $db = $this->resource->getConnection();
        $db->query(sprintf('INSERT IGNORE INTO %s (quote_id) VALUES (?)', $db->quoteIdentifier($this->name())), [$quoteId]);
    }

    /** @return array<string, mixed>|null the cart's send history, or null when it was never queued */
    public function load(int $quoteId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->name())->where('quote_id = ?', $quoteId));
        return $row ?: null;
    }

    private function subscribed(string $quoteIdColumn): string
    {
        return sprintf("s.type = '%s' AND s.reference_id = %s", CartReminder::CODE, $quoteIdColumn);
    }
}
