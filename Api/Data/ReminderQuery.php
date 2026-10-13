<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Api\Data;

/**
 * SQL context for the quote being selected or claimed. A future policy can reference the quote id
 * in a delivery-history subquery, without adding parameters or methods to ReminderPolicyInterface.
 * @api
 */
class ReminderQuery
{
    public function __construct(public readonly int $storeId, private readonly string $tableAlias = '')
    {
        if ($tableAlias !== '' && !preg_match('/^[a-z_][a-z0-9_]*$/iD', $tableAlias)) {
            throw new \InvalidArgumentException('The quote alias must be a SQL identifier.');
        }
    }

    /** A trusted quote-column identifier, optionally qualified by the module's table alias. */
    public function column(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/iD', $name)) {
            throw new \InvalidArgumentException('The quote column must be a SQL identifier.');
        }
        return ($this->tableAlias !== '' ? $this->tableAlias . '.' : '') . $name;
    }
}
