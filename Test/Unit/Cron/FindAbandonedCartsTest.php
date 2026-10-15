<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Cron;

use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Cron\FindAbandonedCarts;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\QueueInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FindAbandonedCartsTest extends TestCase
{
    use DbTestTrait;

    private array $published = [];
    private ?ReminderPolicyInterface $policy = null;
    private array $unresolved = [];
    private bool $recent = false;
    private bool $publishFails = false;
    private int $perHour = 0;
    private array $logs = [];

    private function finder(bool $enabled = true): FindAbandonedCarts
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($id) => $enabled && $id !== 3);
        $config->method('getDelayMinutes')->willReturnCallback(fn ($id) => $id === 1 ? 180 : 60);
        $config->method('getMaxPerHour')->willReturnCallback(fn () => $this->perHour);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function ($message) { $this->logs[] = $message; });
        $lifetime = $this->createStub(QuoteLifetime::class);
        $lifetime->method('validCondition')->willReturnCallback(fn ($column, $id) => $column . ' > NOW() - INTERVAL ' . ($id === 1 ? 30 : 2) . ' DAY');
        $stores = $this->createStub(StoreManagerInterface::class);
        $values = [];
        foreach ([1, 2, 3] as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $values[$id] = $store;
        }
        $stores->method('getStores')->willReturn($values);
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('publish')->willReturnCallback(function ($type, $id) {
            if ($this->publishFails) {
                throw new \RuntimeException('Fixture queue failure');
            }
            $this->published[] = [$type, $id];
        });
        $recipients = $this->createStub(ReminderEmailResolverInterface::class);
        $recipients->method('resolve')->willReturnCallback(fn ($id) => in_array($id, $this->unresolved, true) ? null : 'fixture@example.test');
        $cooldown = $this->createStub(CooldownInterface::class);
        $cooldown->method('isRecent')->willReturnCallback(fn () => $this->recent);
        return new FindAbandonedCarts($config, $this->resource(), $this->guard(), $queue,
            $this->policy ?? new OncePerCart(), $lifetime, $stores, $logger, $recipients, $cooldown, $this->carts());
    }

    public function testDisabledStoresDoNothing(): void
    {
        $this->assertSame(0, $this->finder(false)->execute());
        $this->assertSame([], $this->updates);
    }

    /** Candidate rows in the order returned by the finder */
    private static function due(array $ids): array
    {
        return array_map(fn ($id) => ['entity_id' => $id, 'subscription_id' => (string)($id + 1000),
            'updated_at' => '2026-10-01 00:00:00'], $ids);
    }

    public function testSelectionUsesConsentPolicyAndEachStoresLifetimeAndDelay(): void
    {
        $this->finder()->execute();
        $conditions = array_column($this->selectWhere, 0);
        foreach (['q.is_active = 1', 'q.items_count > 0', 'VALID(s.consent_id)', 'c.queued_at IS NULL'] as $condition) {
            $this->assertContains($condition, $conditions);
        }
        $branches = end($conditions);
        $this->assertStringContainsString('q.store_id = 1 AND q.updated_at <= NOW() - INTERVAL 180 MINUTE', $branches);
        $this->assertStringContainsString('q.updated_at > NOW() - INTERVAL 30 DAY', $branches);
        $this->assertStringContainsString('q.store_id = 2 AND q.updated_at <= NOW() - INTERVAL 60 MINUTE', $branches);
        $this->assertStringContainsString('q.updated_at > NOW() - INTERVAL 2 DAY', $branches);
        $this->assertStringNotContainsString('store_id = 3', $branches);
        $this->assertStringContainsString('c.reminded_at IS NULL', $branches);
        $this->assertStringContainsString('s.captured_at <= NOW() - INTERVAL 180 MINUTE', $branches);
        $this->assertSame([[200, 0]], $this->limits);
    }

    public function testEverySelectedCartIsQueuedWithItsSubscriptionWithoutAddressGrouping(): void
    {
        $this->fetchAllResults = [self::due([10, 11, 12])];
        $this->assertSame(3, $this->finder()->execute());
        $this->assertSame(['1010', '1011', '1012'], array_column($this->published, 1));
        $this->assertSame(3, count(array_filter($this->queries, fn ($query) => str_contains($query[0], 'INSERT IGNORE'))),
            'the send history row exists before its claim');
    }

    public function testAlternativePolicyIsUsedInSelectionAndQueueClaims(): void
    {
        $this->policy = $this->createStub(ReminderPolicyInterface::class);
        $this->policy->method('dueCondition')->willReturnCallback(fn (ReminderQuery $query) =>
            'CUSTOM(' . $query->column('reminded_at') . ',' . $query->storeId . ')');
        $this->fetchAllResults = [self::due([10])];
        $this->assertSame(1, $this->finder()->execute());
        $this->assertStringContainsString('CUSTOM(c.reminded_at,1)', end($this->selectWhere)[0]);
        $this->assertStringContainsString('CUSTOM(c.reminded_at,1)', implode(' ', self::conditions($this->updates[1]['where'])));
    }

    public function testCartsStuckForADayAreFoundAgain(): void
    {
        $this->finder()->execute();
        $stuck = $this->updates[0];
        $this->assertSame('ck_abandoned_cart', $stuck['table']);
        $this->assertSame(['queued_at' => null], $stuck['data'], 'Magento\'s quote row is not written');
        $this->assertSame(['queued_at < NOW() - INTERVAL 24 HOUR', 'reminded_at IS NULL OR reminded_at < queued_at'],
            self::conditions($stuck['where']), 'only a mark that never led to a reminder');
    }

    public function testOnlyWonAtomicClaimsArePublished(): void
    {
        $this->fetchAllResults = [self::due([10, 11, 12])];
        $this->updateResults = [0, 1, 0, 1];
        $this->assertSame(2, $this->finder()->execute());
        $this->assertSame([[CartReminder::CODE, '1010'], [CartReminder::CODE, '1012']], $this->published);
        $claim = $this->updates[1];
        $this->assertSame(self::joined(), $claim['table']);
        $this->assertSame(['c.queued_at'], array_keys($claim['data']), 'one mark, no reservation bookkeeping');
        $this->assertSame('NOW()', (string)$claim['data']['c.queued_at']);
        $this->assertSame(10, $claim['where']['c.quote_id = ?']);
        $this->assertSame(1010, $claim['where']['s.subscription_id = ?'], 'the selected subscription, not a newer one');
        $conditions = self::conditions($claim['where']);
        $this->assertContains('c.queued_at IS NULL', $conditions);
        $this->assertContains('VALID(s.consent_id)', $conditions);
        $this->assertStringContainsString('updated_at > NOW() - INTERVAL 30 DAY', implode(' ', $conditions));
    }

    public function testRecentRecipientsAreNotQueued(): void
    {
        $this->recent = true;
        $this->fetchAllResults = [self::due([10, 11])];
        $this->assertSame(0, $this->finder()->execute());
        $this->assertSame([], $this->published);
    }

    public function testPublishFailureClearsTheMarkAndPropagates(): void
    {
        $this->publishFails = true;
        $this->fetchAllResults = [self::due([10, 11])];
        try {
            $this->finder()->execute();
            $this->fail('A failed publish must not be reported as queued successfully.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fixture queue failure', $error->getMessage());
        }
        $this->assertSame([], $this->published);
        $this->assertCount(3, $this->updates, 'stuck carts, the first claim, then its mark cleared');
        $this->assertSame(10, $this->updates[1]['where']['c.quote_id = ?']);
        $this->assertSame(['queued_at' => null], $this->updates[2]['data']);
        $this->assertSame(['quote_id = ?', 'reminded_at IS NULL OR reminded_at < queued_at'], self::conditions($this->updates[2]['where']));
        $this->assertSame(10, $this->updates[2]['where']['quote_id = ?'], 'the next run publishes it again');
    }

    public function testNothingDue(): void
    {
        $this->assertSame(0, $this->finder()->execute());
        $this->assertSame([], $this->published);
    }

    public function testUnresolvedRecipientsDoNotBlockTheNextCandidatePage(): void
    {
        $this->unresolved = range(1, 200);
        $this->fetchAllResults = [self::due(range(1, 200)), self::due([201])];
        $this->assertSame(1, $this->finder()->execute());
        $this->assertSame([[CartReminder::CODE, '1201']], $this->published);
        $this->assertSame([[200, 0], [200, 0]], $this->limits);
        $this->assertStringContainsString("q.entity_id > '200'", end($this->selectWhere)[0]);
    }

    public function testEveryDueCartIsPublishedAcrossPages(): void
    {
        $this->fetchAllResults = [self::due(range(1, 200)), self::due(range(201, 400)), self::due(range(401, 450))];
        $this->assertSame(450, $this->finder()->execute(), 'the queue holds the backlog; no cap on what a run publishes');
        $this->assertSame([[200, 0], [200, 0], [200, 0]], $this->limits, 'pages of 200; the cursor follows the last scanned cart');
        $this->assertSame(['CommerceKings_AbandonedCart: queued 450 cart reminder(s)'], $this->logs);
    }

    public function testHourlyLimitPublishesOnlyWhatStillFitsInTheHour(): void
    {
        $this->perHour = 100;
        $this->fetchOneResults = [90]; // sent in the last hour plus queued and not sent yet
        $this->fetchAllResults = [self::due(range(1, 50))];
        $this->assertSame(10, $this->finder()->execute());
        $this->published = [];
        $this->fetchOneResults = [100];
        $this->fetchAllResults = [self::due(range(1, 50))];
        $this->assertSame(0, $this->finder()->execute(), 'the hour is used up; the rest waits');
        $this->assertSame([], $this->published);
    }
}
