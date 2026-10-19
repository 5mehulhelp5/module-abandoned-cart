<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Recording fake for the direct SQL this module runs: every update/insert/delete is kept with its
 * data and WHERE conditions, reads return what the test queues. Table names are passed through.
 */
trait DbTestTrait
{
    /** @var array<int, array{table: string, data: array, where: array}> */
    private array $updates = [];
    private array $inserts = [];
    private array $deletes = [];
    /** @var array<int, array{0: string, 1: mixed}> where() calls of the last select */
    private array $selectWhere = [];
    /** queued return values of update() (rows affected), default 1; a \Throwable is thrown instead */
    private array $updateResults = [];
    private mixed $fetchRowResult = false;
    private mixed $fetchOneResult = false;
    /** queued fetchOne() results, one per call; fetchOneResult when none left */
    private array $fetchOneResults = [];
    private array $fetchColResult = [];
    private array $fetchColResults = [];
    /** queued fetchPairs() results, one per call; [] when none left */
    private array $fetchPairsResults = [];
    private array $fetchAllResults = [];
    /** limit() calls of selects: [count, offset] */
    private array $limits = [];
    /** raw query() calls: [sql, bind]; queued rowCount() results, default 1 */
    private array $queries = [];
    private array $queryRowCounts = [];
    private array $transactionEvents = [];
    private array $rowLocks = [];

    private function resource(): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'order', 'joinInner', 'join', 'joinLeft'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('forUpdate')->willReturnCallback(function ($flag) use ($select) {
            $this->rowLocks[] = $flag;
            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($count = null, $offset = null) use ($select) {
            $this->limits[] = [$count, $offset];
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->selectWhere[] = [$cond, $value];
            return $select;
        });

        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $db->method($method)->willReturnCallback(function () use ($method, $db) {
                $this->transactionEvents[] = $method;
                return $db;
            });
        }
        $db->method('fetchAll')->willReturnCallback(function () {
            $this->transactionEvents[] = 'lock';
            return $this->fetchAllResults ? array_shift($this->fetchAllResults) : [];
        });
        $db->method('update')->willReturnCallback(function ($table, array $data, $where = '') {
            // a joined table (CartTable::joined()) is an expression: record its SQL text
            $this->updates[] = ['table' => (string)$table, 'data' => $data, 'where' => (array)$where];
            $result = $this->updateResults ? array_shift($this->updateResults) : 1;
            if ($result instanceof \Throwable) {
                throw $result; // e.g. a deadlock: the statement was not applied
            }
            return $result;
        });
        $db->method('insertOnDuplicate')->willReturnCallback(function ($table, array $data, array $fields = []) {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            return 1;
        });
        $db->method('delete')->willReturnCallback(function ($table, $where = '') {
            $this->deletes[] = ['table' => $table, 'where' => (array)$where];
            return 3;
        });
        $db->method('fetchRow')->willReturnCallback(fn () => $this->fetchRowResult);
        $db->method('fetchOne')->willReturnCallback(fn () => $this->fetchOneResults ? array_shift($this->fetchOneResults) : $this->fetchOneResult);
        $db->method('fetchCol')->willReturnCallback(fn () => $this->fetchColResults ? array_shift($this->fetchColResults) : $this->fetchColResult);
        $db->method('getCheckSql')->willReturnCallback(fn ($condition, $yes, $no) => 'IF(' . $condition . ', ' . $yes . ', ' . $no . ')');
        $db->method('fetchPairs')->willReturnCallback(fn () => $this->fetchPairsResults ? array_shift($this->fetchPairsResults) : []);
        $db->method('query')->willReturnCallback(function ($sql, $bind = []) {
            $this->queries[] = [$sql, $bind];
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $statement->method('rowCount')->willReturn($this->queryRowCounts ? array_shift($this->queryRowCounts) : 1);
            return $statement;
        });
        $db->method('quoteInto')->willReturnCallback(fn ($text, $value) => str_replace('?', "'" . $value . "'", $text));
        $db->method('quote')->willReturnCallback(fn ($value) => "'" . $value . "'");
        $db->method('quoteIdentifier')->willReturnArgument(0);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    /** The module's real CartTable on this fake; joined() as self::joined() below. */
    private function carts(): \CommerceKings\AbandonedCart\Model\CartTable
    {
        $resource = $this->resource();
        return new \CommerceKings\AbandonedCart\Model\CartTable($resource,
            new \CommerceKings\Notification\Model\DeliveryState($resource));
    }

    /** The built-in email reminder over the test's own config, recipients, email data and policy (wiring only). */
    private function emailReminder(\CommerceKings\AbandonedCart\Model\Config $config,
        ?\CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface $recipients = null,
        ?\CommerceKings\AbandonedCart\Model\ReminderData $data = null,
        ?\CommerceKings\AbandonedCart\Api\ReminderPolicyInterface $policy = null,
        ?\CommerceKings\Notification\Model\Config $notificationConfig = null
    ): \CommerceKings\AbandonedCart\Model\Reminder\EmailReminder {
        return new \CommerceKings\AbandonedCart\Model\Reminder\EmailReminder($config,
            $recipients ?? $this->createStub(\CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface::class),
            $data ?? $this->createStub(\CommerceKings\AbandonedCart\Model\ReminderData::class),
            $policy ?? new \CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart(),
            $notificationConfig ?? $this->createStub(\CommerceKings\Notification\Model\Config::class));
    }

    /** CartTable::joined() as the fake records it (traits hold no constants before PHP 8.2). */
    private static function joined(): string
    {
        return 'ck_notification_delivery_state AS c INNER JOIN ck_notification_subscription AS s ON c.type = s.type'
            . ' AND c.reference_id = s.reference_id AND c.subject_key = s.subject_key'
            . ' INNER JOIN quote AS q ON q.entity_id = s.reference_id';
    }

    /** All WHERE conditions of an update as plain strings ("col = ?" keys and numeric-key conditions). */
    private static function conditions(array $where): array
    {
        $out = [];
        foreach ($where as $key => $value) {
            $out[] = is_int($key) ? $value : $key;
        }
        return $out;
    }

    /**
     * Notification's permission whose SQL condition is a readable marker, VALID(<column>); the PHP recheck accepts
     * $validReferences for Config::PURPOSE (null: any reference).
     */
    private function guard(?array $validReferences = null): \CommerceKings\Notification\Api\SubscriptionPermissionInterface
    {
        $guard = $this->createStub(\CommerceKings\Notification\Api\SubscriptionPermissionInterface::class);
        $guard->method('validCondition')->willReturnCallback(fn ($column) => 'VALID(' . $column . ')');
        $guard->method('isValidFor')->willReturnCallback(fn ($reference, $purpose) => $purpose === 'cart_reminder'
            && ($validReferences === null || in_array($reference, $validReferences, true)));
        return $guard;
    }

    /** mockWith() value: the method returns the mock itself (fluent setters, load()). */
    private static string $SELF = '__self__';

    /** Builds a mock that has both real and magic (__call) methods, e.g. Quote::getIsActive(). */
    private function mockWith(string $class, array $returns): object
    {
        $real = array_values(array_filter(array_keys($returns), fn ($m) => method_exists($class, $m)));
        $magic = array_values(array_diff(array_keys($returns), $real));
        // Magento data getters/setters use __call. Stub that real method instead of addMethods(),
        // which PHPUnit 12 removed, so these fixtures work with PHPUnit 9 through 12.
        $mock = $this->createStub($class);
        if ($magic) {
            $mock->method('__call')->willReturnCallback(
                static function ($method, $arguments) use ($returns, $magic, $mock) {
                    if (!in_array($method, $magic, true)) {
                        throw new \BadMethodCallException('Unexpected magic method: ' . $method);
                    }
                    $value = $returns[$method];
                    return $value === self::$SELF ? $mock
                        : ($value instanceof \Closure ? $value(...$arguments) : $value);
                }
            );
        }
        foreach (array_intersect_key($returns, array_flip($real)) as $method => $value) {
            if ($value === self::$SELF) {
                $mock->method($method)->willReturnSelf();
            } elseif ($value instanceof \Closure) {
                $mock->method($method)->willReturnCallback($value);
            } else {
                $mock->method($method)->willReturn($value);
            }
        }
        return $mock;
    }
}
