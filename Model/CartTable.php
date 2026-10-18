<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\SubscriptionManagement;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

/**
 * A cart's send history - Notification's delivery state for one reminder type (alias "c"; the email reminder unless
 * forType()), one row per cart and type (a reminder is a single type: its key is the quote id) - with Magento's cart
 * (quote, alias "q") and the cart's CommerceKings_Notification subscription of that type (alias "s": contact, consent,
 * browser, captured_at). The state is kept per
 * cart, not per subscription, so a withdrawal and a new sign-up never allow a second reminder.
 * joined() is a table expression for the adapter's update(): one atomic UPDATE can still check the cart, the
 * subscription's consent and the send history together.
 */
class CartTable
{
    /** Delay before the next try after the 1st, 2nd, ... failed send; after the last one the cart is given up. */
    public const RETRY_MINUTES = [60, 240, 1440, 1440];

    private string $type = CartReminder::CODE;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeliveryStateInterface $state
    ) {
    }

    /** The same table for another reminder type's carts (ReminderTypeInterface::getCode()). */
    public function forType(string $typeCode): self
    {
        $copy = clone $this;
        $copy->type = $typeCode;
        return $copy;
    }

    /** Notification's delivery state table, shared with other features: use key() or ofType() in every statement. */
    public function name(): string
    {
        return $this->state->getTableName();
    }

    /**
     * WHERE conditions (bound) for one cart's send history.
     * @param string $alias "c" in joined() and select(), '' on the table alone
     */
    public function key(int $quoteId, string $alias = ''): array
    {
        return $this->state->keyCondition($this->type, $quoteId, '', $alias);
    }

    /** WHERE condition (bound): every cart's send history of this type, none of another type's or feature's. */
    public function ofType(): array
    {
        return ['type = ?' => $this->type];
    }

    /** "<delivery state> AS c INNER JOIN ck_notification_subscription AS s … INNER JOIN quote AS q …" for UPDATEs. */
    public function joined(): Expression
    {
        $db = $this->resource->getConnection();
        return new Expression(sprintf(
            '%s AS c INNER JOIN %s AS s ON %s INNER JOIN %s AS q ON q.entity_id = s.reference_id',
            $db->quoteIdentifier($this->name()),
            $db->quoteIdentifier($this->resource->getTableName(SubscriptionManagement::TABLE)),
            $this->state->joinCondition('c', 's'),
            $db->quoteIdentifier($this->resource->getTableName('quote'))));
    }

    /**
     * SELECT … FROM quote q JOIN its subscription s, with the send history c (no row yet = never queued) and
     * the guest contact n.
     */
    public function select(array $cartColumns = [], array $quoteColumns = [], array $subscriptionColumns = []): Select
    {
        return $this->resource->getConnection()->select()
            ->from(['q' => $this->resource->getTableName('quote')], $quoteColumns)
            ->join(['s' => $this->resource->getTableName(SubscriptionManagement::TABLE)],
                $this->resource->getConnection()->quoteInto('s.type = ? AND s.reference_id = q.entity_id', $this->type),
                $subscriptionColumns)
            ->joinLeft(['c' => $this->name()], $this->state->joinCondition('c', 's'), $cartColumns)
            ->joinLeft(['n' => $this->resource->getTableName(ContactRepository::TABLE)], 'n.contact_id = s.contact_id', []);
    }

    /** Create the cart's send history row before its first conditional update; an existing row is kept. */
    public function ensure(int $quoteId): void
    {
        $this->state->ensure($this->type, $quoteId);
    }

    /** @return array<string, mixed>|null the cart's send history, or null when it was never queued */
    public function load(int $quoteId): ?array
    {
        return $this->state->load($this->type, $quoteId);
    }
}
