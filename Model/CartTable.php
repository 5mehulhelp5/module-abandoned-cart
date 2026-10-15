<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Notification\Model\ContactRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

/**
 * The module's reminder state (ck_abandoned_cart, alias "c") joined to Magento's cart (quote, alias "q").
 * joined() is a table expression for the adapter's update(): one atomic UPDATE can still check the cart, the
 * consent guard and the reminder state together.
 */
class CartTable
{
    public const TABLE = 'ck_abandoned_cart';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function name(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /** "ck_abandoned_cart AS c INNER JOIN quote AS q ON …" for UPDATE statements. */
    public function joined(): Expression
    {
        $db = $this->resource->getConnection();
        return new Expression(sprintf('%s AS c INNER JOIN %s AS q ON q.entity_id = c.quote_id',
            $db->quoteIdentifier($this->name()), $db->quoteIdentifier($this->resource->getTableName('quote'))));
    }

    /** SELECT … FROM ck_abandoned_cart c JOIN quote q, plus the guest contact as n. */
    public function select(array $cartColumns = [], array $quoteColumns = []): Select
    {
        return $this->resource->getConnection()->select()
            ->from(['c' => $this->name()], $cartColumns)
            ->join(['q' => $this->resource->getTableName('quote')], 'q.entity_id = c.quote_id', $quoteColumns)
            ->joinLeft(['n' => $this->resource->getTableName(ContactRepository::TABLE)], 'n.contact_id = c.contact_id', []);
    }

    /** Create the cart's state row before its first conditional update; an existing row is kept. */
    public function ensure(int $quoteId): void
    {
        $db = $this->resource->getConnection();
        $db->query(sprintf('INSERT IGNORE INTO %s (quote_id) VALUES (?)', $db->quoteIdentifier($this->name())), [$quoteId]);
    }

    /** @return array<string, mixed>|null the cart's reminder state, or null when nothing was captured */
    public function load(int $quoteId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->name())->where('quote_id = ?', $quoteId));
        return $row ?: null;
    }
}
