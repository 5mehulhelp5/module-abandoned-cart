<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Cron;

use CommerceKings\AbandonedCart\Cron\FindAbandonedCarts;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\RecipientHistory;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FindAbandonedCartsTest extends TestCase
{
    use DbTestTrait;

    private array $published = [];
    /** addresses in cooldown (lowercase) */
    private array $recent = [];
    /** recent() calls */
    private array $lookups = [];

    private function finder(bool $enabled = true): FindAbandonedCarts
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getDelayMinutes')->willReturn(180);
        $config->method('getMaxAgeDays')->willReturn(7);
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function ($topic, $id) { $this->published[] = [$topic, $id]; });
        $recipients = $this->createStub(RecipientHistory::class);
        $recipients->method('recent')->willReturnCallback(function (array $emails) {
            $this->lookups[] = $emails;
            return array_values(array_filter($emails, fn ($e) => in_array(strtolower(trim($e)), $this->recent, true)));
        });
        return new FindAbandonedCarts($config, $this->resource(), $this->guard(), $publisher, $recipients, $this->createStub(LoggerInterface::class));
    }

    public function testDisabledDoesNothing(): void
    {
        $this->assertSame(0, $this->finder(false)->execute());
        $this->assertSame([], $this->updates);
    }

    public function testSelectsOnlyCartsWithAValidConsentPastTheDelay(): void
    {
        $this->finder()->execute();
        $where = array_column($this->selectWhere, 1, 0);
        foreach (['is_active = 1', 'items_count > 0', "customer_email IS NOT NULL AND customer_email <> ''",
            'ck_abandoned_consent_id IS NOT NULL', 'VALID(q.ck_abandoned_consent_id)', 'ck_abandoned_reminded_at IS NULL', 'ck_abandoned_queued_at IS NULL'] as $condition) {
            $this->assertArrayHasKey($condition, $where, $condition);
        }
        $this->assertSame(180, $where['updated_at <= NOW() - INTERVAL ? MINUTE']);
        $this->assertSame(7, $where['updated_at >= NOW() - INTERVAL ? DAY']);
    }

    public function testSkipsAddressesRemindedWithinTheCooldownWithoutHashingInSql(): void
    {
        $this->recent = ['b@test.com'];
        $this->fetchPairsResults = [[10 => 'a@test.com', 11 => ' B@Test.com', 12 => 'c@test.com']];
        $this->assertSame(2, $this->finder()->execute());
        $this->assertSame(['10', '12'], array_column($this->published, 1));
        $this->assertSame([['a@test.com', ' B@Test.com', 'c@test.com']], $this->lookups);
        // the key-based hash is computed in PHP: no hashing (and no key) in the SQL
        foreach ($this->selectWhere as [$condition]) {
            $this->assertStringNotContainsString('SHA2', (string)$condition);
            $this->assertStringNotContainsString('ck_abandoned_cart_recipient', (string)$condition);
        }
    }

    public function testOneCartPerAddressPerRun(): void
    {
        $this->fetchPairsResults = [[10 => 'a@test.com', 11 => 'A@test.com ', 12 => 'b@test.com']];
        $this->finder()->execute();
        $this->assertSame(['10', '12'], array_column($this->published, 1));
    }

    public function testCartsInCooldownDoNotHoldUpTheBatch(): void
    {
        $full = [];
        for ($i = 1; $i <= 200; $i++) {
            $full[$i] = 'waiting@test.com';
        }
        $this->recent = ['waiting@test.com'];
        $this->fetchPairsResults = [$full, [500 => 'due@test.com']];
        $this->assertSame(1, $this->finder()->execute());
        $this->assertSame(['500'], array_column($this->published, 1));
        $this->assertSame([[200, 0], [200, 200]], $this->limits);
    }

    public function testReleasesStaleClaimsFirst(): void
    {
        $this->finder()->execute();
        $release = $this->updates[0];
        $this->assertNull($release['data']['ck_abandoned_queued_at']);
        $this->assertSame('updated_at', (string)$release['data']['updated_at']);
        $conditions = self::conditions($release['where']);
        $this->assertContains('ck_abandoned_queued_at < NOW() - INTERVAL 60 MINUTE', $conditions);
        $this->assertContains('ck_abandoned_reminded_at IS NULL', $conditions);
    }

    public function testClaimsEachCartAndPublishesOnlyWonClaims(): void
    {
        $this->fetchPairsResults = [[10 => 'a@test.com', 11 => 'b@test.com', 12 => 'c@test.com']];
        $this->updateResults = [0, 1, 0, 1]; // stale release, then claims for 10 (won), 11 (lost), 12 (won)
        $this->assertSame(2, $this->finder()->execute());
        $this->assertSame([[FindAbandonedCarts::TOPIC, '10'], [FindAbandonedCarts::TOPIC, '12']], $this->published);
        $claim = $this->updates[1];
        $this->assertSame('NOW()', (string)$claim['data']['ck_abandoned_queued_at']);
        $this->assertSame(10, $claim['where']['entity_id = ?']);
        $this->assertContains('ck_abandoned_queued_at IS NULL', self::conditions($claim['where']));
    }

    public function testNothingDue(): void
    {
        $this->assertSame(0, $this->finder()->execute());
        $this->assertSame([], $this->published);
    }
}
