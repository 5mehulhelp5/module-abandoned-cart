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
use Magento\Framework\MessageQueue\PublisherInterface;
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
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function ($topic, $id) { $this->published[] = [$topic, $id]; });
        $recipients = $this->createStub(ReminderEmailResolverInterface::class);
        $recipients->method('resolve')->willReturnCallback(fn ($id) => in_array($id, $this->unresolved, true) ? null : 'fixture@example.test');
        return new FindAbandonedCarts($config, $this->resource(), $this->guard(), $publisher,
            $this->policy ?? new OncePerCart(), $lifetime, $stores, $this->createStub(LoggerInterface::class), $recipients);
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
            'q.ck_abandoned_consent_id IS NOT NULL', 'VALID(q.ck_abandoned_consent_id)', 'q.ck_abandoned_queued_at IS NULL'] as $condition) {
            $this->assertContains($condition, $conditions);
        }
        $branches = end($conditions);
        $this->assertStringContainsString('q.store_id = 1 AND q.updated_at <= NOW() - INTERVAL 180 MINUTE', $branches);
        $this->assertStringContainsString('q.updated_at > NOW() - INTERVAL 30 DAY', $branches);
        $this->assertStringContainsString('q.store_id = 2 AND q.updated_at <= NOW() - INTERVAL 60 MINUTE', $branches);
        $this->assertStringContainsString('q.updated_at > NOW() - INTERVAL 2 DAY', $branches);
        $this->assertStringNotContainsString('store_id = 3', $branches);
        $this->assertStringContainsString('q.ck_abandoned_reminded_at IS NULL', $branches);
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
            'CUSTOM(' . $query->column('ck_abandoned_reminded_at') . ',' . $query->storeId . ')');
        $this->fetchColResult = ['10'];
        $this->assertSame(1, $this->finder()->execute());
        $this->assertStringContainsString('CUSTOM(q.ck_abandoned_reminded_at,1)', end($this->selectWhere)[0]);
        $this->assertStringContainsString('CUSTOM(ck_abandoned_reminded_at,1)', implode(' ', self::conditions($this->updates[1]['where'])));
    }

    public function testStaleClaimsKeepQuoteUpdatedAtAndRespectPolicy(): void
    {
        $this->finder()->execute();
        $release = $this->updates[0];
        $this->assertNull($release['data']['ck_abandoned_queued_at']);
        $this->assertSame('updated_at', (string)$release['data']['updated_at']);
        $conditions = self::conditions($release['where']);
        $this->assertContains('ck_abandoned_queued_at < NOW() - INTERVAL 60 MINUTE', $conditions);
        $this->assertStringContainsString('ck_abandoned_reminded_at IS NULL', implode(' ', $conditions));
    }

    public function testOnlyWonAtomicClaimsArePublished(): void
    {
        $this->fetchColResult = ['10', '11', '12'];
        $this->updateResults = [0, 1, 0, 1];
        $this->assertSame(2, $this->finder()->execute());
        $this->assertSame([[FindAbandonedCarts::TOPIC, '10'], [FindAbandonedCarts::TOPIC, '12']], $this->published);
        $claim = $this->updates[1];
        $this->assertSame('NOW()', (string)$claim['data']['ck_abandoned_queued_at']);
        $this->assertSame('updated_at', (string)$claim['data']['updated_at']);
        $this->assertSame(10, $claim['where']['entity_id = ?']);
        $conditions = self::conditions($claim['where']);
        $this->assertContains('ck_abandoned_queued_at IS NULL', $conditions);
        $this->assertContains('VALID(ck_abandoned_consent_id)', $conditions);
        $this->assertStringContainsString('updated_at > NOW() - INTERVAL 30 DAY', implode(' ', $conditions));
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
        $this->assertSame([[FindAbandonedCarts::TOPIC, '201']], $this->published);
        $this->assertSame([[200, 0], [200, 200]], $this->limits);
    }
}
