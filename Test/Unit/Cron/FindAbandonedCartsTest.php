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

    private function finder(bool $enabled = true): FindAbandonedCarts
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($id) => $enabled && $id !== 3);
        $config->method('getDelayMinutes')->willReturnCallback(fn ($id) => $id === 1 ? 180 : 60);
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
        $queue->method('publish')->willReturnCallback(function ($type, $id) { $this->published[] = [$type, $id]; });
        $recipients = $this->createStub(ReminderEmailResolverInterface::class);
        $recipients->method('resolve')->willReturnCallback(fn ($id) => in_array($id, $this->unresolved, true) ? null : 'fixture@example.test');
        $cooldown = $this->createStub(CooldownInterface::class);
        $cooldown->method('isRecent')->willReturnCallback(fn () => $this->recent);
        return new FindAbandonedCarts($config, $this->resource(), $this->guard(), $queue,
            $this->policy ?? new OncePerCart(), $lifetime, $stores, $this->createStub(LoggerInterface::class), $recipients, $cooldown, $this->carts());
    }

    public function testDisabledStoresDoNothing(): void
    {
        $this->assertSame(0, $this->finder(false)->execute());
        $this->assertSame([], $this->updates);
    }

    public function testSelectionUsesConsentPolicyAndEachStoresLifetimeAndDelay(): void
    {
        $this->finder()->execute();
        $conditions = array_column($this->selectWhere, 0);
        foreach (['q.is_active = 1', 'q.items_count > 0',
            'c.consent_id IS NOT NULL', 'VALID(c.consent_id)', 'c.queued_at IS NULL'] as $condition) {
            $this->assertContains($condition, $conditions);
        }
        $branches = end($conditions);
        $this->assertStringContainsString('q.store_id = 1 AND q.updated_at <= NOW() - INTERVAL 180 MINUTE', $branches);
        $this->assertStringContainsString('q.updated_at > NOW() - INTERVAL 30 DAY', $branches);
        $this->assertStringContainsString('q.store_id = 2 AND q.updated_at <= NOW() - INTERVAL 60 MINUTE', $branches);
        $this->assertStringContainsString('q.updated_at > NOW() - INTERVAL 2 DAY', $branches);
        $this->assertStringNotContainsString('store_id = 3', $branches);
        $this->assertStringContainsString('c.reminded_at IS NULL', $branches);
        $this->assertStringContainsString('(c.captured_at IS NULL OR c.captured_at <= NOW() - INTERVAL 180 MINUTE)', $branches);
        $this->assertSame([[200, 0]], $this->limits);
    }

    public function testEverySelectedCartIsQueuedWithoutAddressGrouping(): void
    {
        $this->fetchColResult = ['10', '11', '12'];
        $this->assertSame(3, $this->finder()->execute());
        $this->assertSame(['10', '11', '12'], array_column($this->published, 1));
    }

    public function testAlternativePolicyIsUsedInSelectionAndQueueClaims(): void
    {
        $this->policy = $this->createStub(ReminderPolicyInterface::class);
        $this->policy->method('dueCondition')->willReturnCallback(fn (ReminderQuery $query) =>
            'CUSTOM(' . $query->column('reminded_at') . ',' . $query->storeId . ')');
        $this->fetchColResult = ['10'];
        $this->assertSame(1, $this->finder()->execute());
        $this->assertStringContainsString('CUSTOM(c.reminded_at,1)', end($this->selectWhere)[0]);
        $this->assertStringContainsString('CUSTOM(c.reminded_at,1)', implode(' ', self::conditions($this->updates[1]['where'])));
    }

    public function testStaleClaimsReleaseOnlyTheCartStateAndRespectPolicy(): void
    {
        $this->finder()->execute();
        $release = $this->updates[0];
        $this->assertSame(self::joined(), $release['table']);
        $this->assertSame(['c.queued_at' => null], $release['data'], 'Magento\'s quote row is not written');
        $conditions = self::conditions($release['where']);
        $this->assertContains('c.queued_at < NOW() - INTERVAL 60 MINUTE', $conditions);
        $this->assertStringContainsString('c.reminded_at IS NULL', implode(' ', $conditions));
    }

    public function testOnlyWonAtomicClaimsArePublished(): void
    {
        $this->fetchColResult = ['10', '11', '12'];
        $this->updateResults = [0, 1, 0, 1];
        $this->assertSame(2, $this->finder()->execute());
        $this->assertSame([[CartReminder::CODE, '10'], [CartReminder::CODE, '12']], $this->published);
        $claim = $this->updates[1];
        $this->assertSame(self::joined(), $claim['table']);
        $this->assertSame(['c.queued_at'], array_keys($claim['data']));
        $this->assertSame('NOW()', (string)$claim['data']['c.queued_at']);
        $this->assertSame(10, $claim['where']['c.quote_id = ?']);
        $conditions = self::conditions($claim['where']);
        $this->assertContains('c.queued_at IS NULL', $conditions);
        $this->assertContains('VALID(c.consent_id)', $conditions);
        $this->assertStringContainsString('updated_at > NOW() - INTERVAL 30 DAY', implode(' ', $conditions));
    }

    public function testRecentRecipientsAreNotQueued(): void
    {
        $this->recent = true;
        $this->fetchColResult = ['10', '11'];
        $this->assertSame(0, $this->finder()->execute());
        $this->assertSame([], $this->published);
    }

    public function testNothingDue(): void
    {
        $this->assertSame(0, $this->finder()->execute());
        $this->assertSame([], $this->published);
    }

    public function testUnresolvedRecipientsDoNotBlockTheNextCandidatePage(): void
    {
        $this->unresolved = range(1, 200);
        $this->fetchColResults = [range(1, 200), [201]];
        $this->assertSame(1, $this->finder()->execute());
        $this->assertSame([[CartReminder::CODE, '201']], $this->published);
        $this->assertSame([[200, 0], [200, 200]], $this->limits);
    }
}
