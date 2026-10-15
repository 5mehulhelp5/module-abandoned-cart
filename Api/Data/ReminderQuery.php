<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api\Data;

/**
 * SQL context for the cart being selected or claimed: column() qualifies a column of the module's
 * send history (ck_abandoned_cart: quote_id, reminded_at, …). A future policy can reference quote_id
 * in a delivery-history subquery, without adding parameters or methods to ReminderPolicyInterface.
 * @api
 */
class ReminderQuery
{
    public function __construct(public readonly int $storeId, private readonly string $tableAlias = '')
    {
        if ($tableAlias !== '' && !preg_match('/^[a-z_][a-z0-9_]*$/iD', $tableAlias)) {
            throw new \InvalidArgumentException('The table alias must be a SQL identifier.');
        }
    }

    /** A trusted reminder-state column identifier, optionally qualified by the module's table alias. */
    public function column(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/iD', $name)) {
            throw new \InvalidArgumentException('The column must be a SQL identifier.');
        }
        return ($this->tableAlias !== '' ? $this->tableAlias . '.' : '') . $name;
    }
}
