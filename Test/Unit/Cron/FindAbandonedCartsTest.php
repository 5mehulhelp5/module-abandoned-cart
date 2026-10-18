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
use CommerceKings\AbandonedCart\Test\Unit\Fixture\TestReminderType;

class FindAbandonedCartsTest extends TestCase
{
    private bool $quiet = false;
    /** quiet for every given recipient, but not for the whole store (a recipient-time-zone preference) */
    private bool $quietPerRecipient = false;
    private array $quietAsked = [];
    use DbTestTrait;

    private array $published = [];
    private ?ReminderPolicyInterface $policy = null;
    private array $unresolved = [];
    private bool $recent = false;
    private bool $publishFails = false;
    private int $perHour = 0;
    /** cooldown checks: [channel, recipient] */
    private array $cooldownAsked = [];
    private array $logs = [];

    /** @param \CommerceKings\AbandonedCart\Api\ReminderTypeInterface[] $extra more reminder types after email */
    private function finder(bool $enabled = true, array $extra = []): FindAbandonedCarts
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
        $cooldown->method('isRecentFor')->willReturnCallback(function ($channel, $recipient) {
            $this->cooldownAsked[] = [$channel, $recipient];
            return $this->recent;
        });
        return new FindAbandonedCarts($this->resource(), $this->guard(), $queue, $lifetime, $stores, $logger, $cooldown,
            $this->carts(), $this->quietHours(), new \CommerceKings\AbandonedCart\Model\ReminderTypePool(['cart_reminder' =>
                $this->emailReminder($config, $recipients, null, $this->policy ?? new OncePerCart())]
                + array_combine(array_map(fn ($type) => $type->getCode(), $extra), $extra)));
    }

    private function quietHours(): \CommerceKings\Notification\Api\QuietHoursInterface
    {
        $quiet = $this->createStub(\CommerceKings\Notification\Api\QuietHoursInterface::class);
        $quiet->method('isQuiet')->willReturnCallback(function ($storeId, $channel, $recipient = null) {
            $this->quietAsked[] = [$storeId, $channel, $recipient];
            return $this->quiet || ($this->quietPerRecipient && $recipient !== null);
        });
        return $quiet;
    }

    /** A store in quiet hours for the reminder's channel queues nothing while they last. */
    public function testAStoreInQuietHoursQueuesNothing(): void
    {
        $this->quiet = true;
        $this->assertSame(0, $this->finder()->execute());
        $this->assertSame([], $this->updates);
        $this->assertSame('email', $this->quietAsked[0][1], 'asked for the reminder\'s channel');
        $this->assertNull($this->quietAsked[0][2], 'asked for the whole store: no recipient is known yet');
    }

    /** Quiet hours per recipient answer false for the whole store: the finder queues, the sender holds each one. */
    public function testQuietHoursPerRecipientLeaveTheFinderAlone(): void
    {
        $this->quietPerRecipient = true;
        $this->fetchAllResults = [self::due([10])];
        $this->assertSame(1, $this->finder()->execute());
        $this->assertSame([[CartReminder::CODE, '1010']], $this->published);
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
        foreach (['q.is_active = 1', 'q.items_count > 0', 'VALID(s.permission_ref)', 'c.queued_at IS NULL',
            '(c.skip_until IS NULL OR c.skip_until <= NOW())', '(c.failed_sends IS NULL OR c.failed_sends <= 4)'] as $condition) {
            $this->assertContains($condition, $conditions);
        }
        $branches = end($conditions);
        $this->assertStringContainsString('q.store_id = 1 AND q.updated_at <= NOW() - INTERVAL 180 MINUTE', $branches);
        $this->assertStringContainsString('q.updated_at > NOW() - INTERVAL 30 DAY', $branches);
        $this->assertStringContainsString('q.store_id = 2 AND q.updated_at <= NOW() - INTERVAL 60 MINUTE', $branches);
        $this->assertStringContainsString('q.updated_at > NOW() - INTERVAL 2 DAY', $branches);
        $this->assertStringNotContainsString('store_id = 3', $branches);
        $this->assertStringContainsString('c.sent_at IS NULL', $branches);
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
        $this->assertStringContainsString('CUSTOM(c.sent_at,1)', end($this->selectWhere)[0]);
        $this->assertStringContainsString('CUSTOM(c.sent_at,1)', implode(' ', self::conditions($this->updates[1]['where'])));
    }

    public function testCartsStuckForADayAreFoundAgain(): void
    {
        $this->finder()->execute();
        $stuck = $this->updates[0];
        $this->assertSame('ck_notification_delivery_state', $stuck['table']);
        $this->assertSame(['queued_at' => null], $stuck['data'], 'Magento\'s quote row is not written');
        $this->assertSame(['queued_at < NOW() - INTERVAL 24 HOUR', 'type = ?'], self::conditions($stuck['where']),
            'sending clears the mark, so a day-old mark never led to a reminder');
        $this->assertSame('cart_reminder', $stuck['where']['type = ?'], 'never another feature\'s state');
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
        $this->assertSame(10, $claim['where']['c.reference_id = ?']);
        $this->assertSame(1010, $claim['where']['s.subscription_id = ?'], 'the selected subscription, not a newer one');
        $conditions = self::conditions($claim['where']);
        $this->assertContains('c.queued_at IS NULL', $conditions);
        $this->assertContains('(c.skip_until IS NULL OR c.skip_until <= NOW())', $conditions);
        $this->assertContains('VALID(s.permission_ref)', $conditions);
        $this->assertStringContainsString('updated_at > NOW() - INTERVAL 30 DAY', implode(' ', $conditions));
    }

    public function testRecentRecipientsAreNotQueuedAndLeftOutForAnHour(): void
    {
        $this->recent = true;
        $this->fetchAllResults = [self::due([10, 11])];
        $this->assertSame(0, $this->finder()->execute());
        $this->assertSame([], $this->published);
        // not scanned again on every run ahead of newer carts (oldest first)
        $skips = array_values(array_filter($this->updates, fn ($u) => isset($u['data']['skip_until'])));
        $this->assertSame([10, 11], array_map(fn ($u) => $u['where']['reference_id = ?'], $skips));
        $this->assertSame('ck_notification_delivery_state', $skips[0]['table'], 'Magento\'s quote row is not written');
        $this->assertSame(['skip_until'], array_keys($skips[0]['data']), 'queued_at and sent_at stay untouched');
        $this->assertSame('NOW() + INTERVAL 60 MINUTE', (string)$skips[0]['data']['skip_until']);
        $this->assertSame(2, count(array_filter($this->queries, fn ($query) => str_contains($query[0], 'INSERT IGNORE'))),
            'the send history row exists before the skip is written');
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
        $this->assertSame(10, $this->updates[1]['where']['c.reference_id = ?']);
        $this->assertSame(['queued_at' => null], $this->updates[2]['data']);
        $this->assertSame(['type = ?', 'reference_id = ?', 'subject_key = ?'], self::conditions($this->updates[2]['where']));
        $this->assertSame(10, $this->updates[2]['where']['reference_id = ?'], 'the next run publishes it again');
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
        $this->assertCount(200, array_filter($this->updates, fn ($u) => isset($u['data']['skip_until'])),
            'carts without an address are left out for an hour too');
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
        $this->assertContains(['type = ?', 'cart_reminder'], $this->selectWhere, 'only cart reminders count');
    }

    /** A channel module's type: its own carts, code, channel, cooldown key and stuck reset; email is unchanged. */
    public function testEachReminderTypeQueuesItsOwnCarts(): void
    {
        $this->fetchAllResults = [self::due([10]), self::due([20])];
        $this->assertSame(2, $this->finder(true, [new TestReminderType()])->execute());
        $this->assertSame([[CartReminder::CODE, '1010'], ['cart_reminder_test', '1020']], $this->published);
        $this->assertSame([['email', 'fixture@example.test'], ['sms', '+381601234567']], $this->cooldownAsked,
            'each recipient checked on its own channel');
        $stuck = array_values(array_filter($this->updates, fn ($u) => isset($u['where']['type = ?'])));
        $this->assertSame([CartReminder::CODE, 'cart_reminder_test'], array_map(fn ($u) => $u['where']['type = ?'], $stuck));
        $claims = array_values(array_filter($this->updates, fn ($u) => isset($u['where']['c.type = ?'])));
        $this->assertSame([[CartReminder::CODE, 10], ['cart_reminder_test', 20]],
            array_map(fn ($u) => [$u['where']['c.type = ?'], $u['where']['c.reference_id = ?']], $claims),
            'every claim names its own type: one send history per type and cart');
        $this->assertSame(['email', 'sms'], array_values(array_unique(array_column($this->quietAsked, 1))));
    }

    /** One hourly limit per type (its own provider): a full email hour does not stop another channel. */
    public function testEachTypeHasItsOwnHourlyLimit(): void
    {
        $this->perHour = 1;
        $this->fetchOneResults = [1, 0, 0, 0]; // email: 1 sent, 0 queued; test type: 0 sent, 0 queued
        $this->fetchAllResults = [self::due([20])];
        $published = $this->finder(true, [new TestReminderType(perHour: 5)])->execute();
        $this->assertSame(1, $published);
        $this->assertSame([['cart_reminder_test', '1020']], $this->published, 'email\'s hour is used up, the other is not');
        $this->assertContains(['type = ?', CartReminder::CODE], $this->selectWhere);
        $this->assertContains(['type = ?', 'cart_reminder_test'], $this->selectWhere);
    }

    /** A type that is off in every store, or a recipient it cannot find, never touches the email reminder. */
    public function testAnotherTypeOffOrWithoutRecipientIsLeftAlone(): void
    {
        $this->fetchAllResults = [self::due([10]), self::due([20])];
        $published = $this->finder(true, [new TestReminderType(enabled: false),
            new TestReminderType('cart_reminder_other', 'viber', recipients: [20 => null])])->execute();
        $this->assertSame(1, $published);
        $this->assertSame([[CartReminder::CODE, '1010']], $this->published);
        $skips = array_values(array_filter($this->updates, fn ($u) => isset($u['data']['skip_until'])));
        $this->assertSame('cart_reminder_other', $skips[0]['where']['type = ?'], 'no recipient: its own cart is skipped');
    }
}
