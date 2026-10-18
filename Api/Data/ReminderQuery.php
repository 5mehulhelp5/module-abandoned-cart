<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api\Data;

/**
 * SQL context for the cart being selected or claimed. quoteId() is the cart's id, present in every selection and
 * claim - reference it in a policy's own delivery-history subquery. column() qualifies a column of the cart's send
 * history (Notification's delivery state for the cart): queued_at or reminded_at (stored there as sent_at); both are
 * NULL for a cart that was never queued.
 * Anything else is refused here rather than failing later in SQL.
 * @api
 */
class ReminderQuery
{
    /** Send-history columns a policy may reference. */
    public const COLUMNS = ['queued_at', 'reminded_at'];
    /** Their names in Notification's delivery state. */
    private const STORED = ['queued_at' => 'queued_at', 'reminded_at' => 'sent_at'];

    public function __construct(
        public readonly int $storeId,
        private readonly string $tableAlias = '',
        private readonly string $quoteAlias = ''
    ) {
        foreach ([$tableAlias, $quoteAlias] as $alias) {
            if ($alias !== '' && !preg_match('/^[a-z_][a-z0-9_]*$/iD', $alias)) {
                throw new \InvalidArgumentException('The table alias must be a SQL identifier.');
            }
        }
    }

    /** The cart's id (Magento's quote.entity_id), qualified by the cart's alias. */
    public function quoteId(): string
    {
        return ($this->quoteAlias !== '' ? $this->quoteAlias . '.' : '') . 'entity_id';
    }

    /** A send-history column (self::COLUMNS), qualified by the send history's alias. */
    public function column(string $name): string
    {
        if (!in_array($name, self::COLUMNS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown reminder column "%s"; use quoteId() for the cart.', $name));
        }
        return ($this->tableAlias !== '' ? $this->tableAlias . '.' : '') . self::STORED[$name];
    }
}
