<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Integration;

use CommerceKings\AbandonedCart\Block\Adminhtml\System\Config\QuoteLifetime as LifetimeField;
use CommerceKings\AbandonedCart\Block\Adminhtml\System\Config\ReminderPolicy as PolicyField;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Cron\FindAbandonedCarts;
use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\Notification\Model\SubscriptionManagement;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\AbandonedCart\Model\RestoreAccess;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\Notification\Model\Config as NotificationConfig;
use CommerceKings\Notification\Model\Cooldown;
use CommerceKings\Notification\Model\Mailer;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterfaceFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Real SQL, isolated store/quote fixtures. Transport and queue are stubs: no emails are sent. */
class ReminderPolicyTest extends TestCase
{
    private ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private StoreManagerInterface $stores;
    private array $storeIds = [];
    private array $quoteIds = [];
    private array $published = [];
    private int $perHour = 0;
    /** a replaced reminder policy; null = the default OncePerCart */
    private ?ReminderPolicyInterface $policy = null;
    private int $sent = 0;
    private string $email;
    private bool $sendFails = false;
    private bool $publishFails = false;
    private bool $acceptedBeforePublishFailure = false;

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        require_once $root . '/app/bootstrap.php';
        $this->email = 'ck-policy-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(State::class)->setAreaCode('frontend');
        $this->resource = $this->om->get(ResourceConnection::class);
        $this->stores = $this->om->get(StoreManagerInterface::class);
        $this->stores->setCurrentStore('default');
        $store = $this->stores->getStore();
        foreach ([30, 2] as $days) {
            $db = $this->resource->getConnection();
            $db->insert($this->table('store'), ['code' => 'ck-reminder-' . bin2hex(random_bytes(6)),
                'website_id' => $store->getWebsiteId(), 'group_id' => $store->getStoreGroupId(),
                'name' => 'Reminder test', 'is_active' => 0]);
            $this->storeIds[$days] = (int)$db->lastInsertId($this->table('store'));
        }
        $this->stores->reinitStores();
        $this->stores->setCurrentStore('default');
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        if ($this->quoteIds) {
            $emails = $db->fetchCol($db->select()->from(['s' => $this->table(SubscriptionManagement::TABLE)], [])
                ->join(['n' => $this->table('ck_notification_contact')], 'n.contact_id = s.contact_id', 'email')
                ->where('s.type = ?', CartReminder::CODE)->where('s.reference_id IN (?)', $this->quoteIds));
            $hashes = array_map(fn ($email) => $this->cooldown()->hash($email), array_filter($emails));
            if ($hashes) { $db->delete($this->table('ck_notification_cooldown'), ['recipient_hash IN (?)' => $hashes]); }
            $db->delete($this->table(SubscriptionManagement::TABLE),
                ['type = ?' => CartReminder::CODE, 'reference_id IN (?)' => $this->quoteIds]);
            $db->delete($this->table('quote'), ['entity_id IN (?)' => $this->quoteIds]);
            if ($emails) { $db->delete($this->table('ck_notification_contact'), ['email IN (?)' => $emails]); }
        }
        if ($this->storeIds) { $db->delete($this->table('store'), ['store_id IN (?)' => array_values($this->storeIds)]); }
        $this->stores->reinitStores();
        $this->stores->setCurrentStore('default');
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    private function quote(int $days = 30, int $ageSeconds = 120): int
    {
        $quote = $this->om->get(QuoteFactory::class)->create();
        $quote->setStore($this->stores->getStore())->setIsActive(true)->setCustomerIsGuest(true);
        $this->om->get(CartRepositoryInterface::class)->save($quote);
        $id = (int)$quote->getId();
        $this->quoteIds[] = $id;
        $updatedAt = gmdate('Y-m-d H:i:s', time() - $ageSeconds);
        $this->resource->getConnection()->update($this->table('quote'), ['store_id' => $this->storeIds[$days],
            'items_count' => 1, 'updated_at' => $updatedAt], ['entity_id = ?' => $id]);
        $this->resource->getConnection()->insert($this->table(SubscriptionManagement::TABLE), [
            'type' => CartReminder::CODE, 'reference_id' => $id, 'store_id' => $this->storeIds[$days], 'subject_key' => '',
            'permission_ref' => 'fixture-consent', 'captured_at' => $updatedAt]);
        $this->state($id, ['email' => $this->email]);
        return $id;
    }

    /**
     * Write the cart's subscription (email → the guest contact of the cart's website, permission_ref, captured_at)
     * and its send history (queued_at, reminded_at).
     */
    private function state(int $id, array $values): void
    {
        $db = $this->resource->getConnection();
        $subscription = array_intersect_key($values, array_flip(['email', 'permission_ref', 'captured_at']));
        if (isset($subscription['email'])) {
            $storeId = (int)$db->fetchOne($db->select()->from($this->table('quote'), 'store_id')->where('entity_id = ?', $id));
            $subscription['contact_id'] = $this->om->get(\CommerceKings\Notification\Api\ContactRepositoryInterface::class)
                ->save((int)$this->stores->getStore($storeId)->getWebsiteId(), $subscription['email']);
            unset($subscription['email']);
        }
        if ($subscription) {
            $db->update($this->table(SubscriptionManagement::TABLE), $subscription,
                ['type = ?' => CartReminder::CODE, 'reference_id = ?' => $id]);
        }
        $history = array_diff_key($values, array_flip(['email', 'permission_ref', 'captured_at']));
        if ($history) {
            $carts = $this->om->get(CartTable::class);
            $carts->ensure($id);
            $db->update($carts->name(), $history, ['quote_id = ?' => $id]);
        }
    }

    /** The quote with its subscription, guest email and send history. */
    private function row(int $id): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()
            ->from(['q' => $this->table('quote')])
            ->joinLeft(['s' => $this->table(SubscriptionManagement::TABLE)],
                $db->quoteInto('s.type = ? AND s.reference_id = q.entity_id', CartReminder::CODE),
                ['subscription_id', 'contact_id', 'permission_ref', 'captured_at'])
            ->joinLeft(['c' => $this->table('ck_abandoned_cart')], 'c.quote_id = q.entity_id', ['queued_at', 'reminded_at'])
            ->joinLeft(['n' => $this->table('ck_notification_contact')], 'n.contact_id = s.contact_id', ['email'])
            ->where('q.entity_id = ?', $id));
    }

    /** The queue message reference of a cart: its subscription id. */
    private function subscriptionOf(int $quoteId): string
    {
        return (string)$this->row($quoteId)['subscription_id'];
    }

    private function quoteOf(int $subscriptionId): int
    {
        $db = $this->resource->getConnection();
        return (int)$db->fetchOne($db->select()->from($this->table(SubscriptionManagement::TABLE), 'reference_id')
            ->where('subscription_id = ?', $subscriptionId));
    }

    private function config(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($id) => in_array($id, $this->storeIds, true));
        $config->method('getDelayMinutes')->willReturn(1);
        $config->method('getMaxPerHour')->willReturnCallback(fn () => $this->perHour);
        $config->method('getTemplate')->willReturn('fixture');
        $config->method('getIdentity')->willReturn('general');
        return $config;
    }

    private function lifetime(): QuoteLifetime
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(fn ($path, $scope, $id) => array_search((int)$id, $this->storeIds, true));
        return new QuoteLifetime($config);
    }

    private function notificationConfig(): NotificationConfig
    {
        $config = $this->createStub(NotificationConfig::class);
        $config->method('getCooldownDays')->willReturn(7);
        return $config;
    }

    private function cooldown(): Cooldown
    {
        return new Cooldown($this->resource,
            $this->om->get(\Magento\Framework\Encryption\EncryptorInterface::class),
            $this->om->get(\Magento\Framework\Math\Random::class), $this->notificationConfig());
    }

    private function guard(): ConsentGuardInterface
    {
        $guard = $this->createStub(ConsentGuardInterface::class);
        $guard->method('validCondition')->willReturn('1 = 1');
        return $guard;
    }

    private function finder(): FindAbandonedCarts
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStores')->willReturn(array_map(fn ($id) => $this->stores->getStore($id), $this->storeIds));
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('publish')->willReturnCallback(function ($type, $id) {
            if (!$this->publishFails || $this->acceptedBeforePublishFailure) {
                $this->published[] = $this->quoteOf((int)$id);
            }
            if ($this->publishFails) {
                throw new \RuntimeException('Fixture queue failure');
            }
        });
        return new FindAbandonedCarts($this->config(), $this->resource, $this->guard(), $queue,
            $this->policy ?? new OncePerCart(), $this->lifetime(), $stores, $this->createStub(LoggerInterface::class),
            $this->om->get(ReminderEmailResolverInterface::class), $this->cooldown(), $this->om->get(CartTable::class));
    }

    private function consumer(): CartReminder
    {
        $consent = $this->createStub(ConsentInterface::class);
        $consent->method('isValid')->willReturn(true);
        $consent->method('get')->willReturn(new Consent('fixture-consent', 'cart_reminder',
            (int)$this->stores->getStore()->getWebsiteId(), 'source', 'ref', '2026-01-01 00:00:00',
            '1', '1', '2026-01-01 00:00:00', '2027-01-01 00:00:00'));
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function () {
            if ($this->sendFails) { throw new \RuntimeException('Fixture SMTP failure'); }
            $this->sent++;
        });
        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('getTransport')->willReturn($transport);
        $data = $this->createStub(ReminderData::class);
        $data->method('build')->willReturn(['customer_name' => '']);
        $mailer = new Mailer($builder, $this->createStub(Emulation::class), $this->cooldown(), $this->notificationConfig());
        return new CartReminder($this->resource, $this->om->get(CartRepositoryInterfaceFactory::class)->create(),
            $data, $mailer, $this->config(), $consent, $this->guard(),
            $this->policy ?? new OncePerCart(), $this->createStub(LoggerInterface::class), $this->om->get(QuoteScope::class), $this->lifetime(),
            $this->om->get(ReminderEmailResolverInterface::class),
            $this->notificationConfig(), $this->om->get(CartTable::class),
            $this->om->get(\Magento\Framework\Lock\LockManagerInterface::class));
    }

    private function withRecipients(CartReminder $consumer, ReminderEmailResolverInterface $recipients): CartReminder
    {
        $class = new \ReflectionClass($consumer);
        $arguments = [];
        foreach ($class->getConstructor()->getParameters() as $parameter) {
            $arguments[] = $parameter->getName() === 'recipients' ? $recipients
                : $class->getProperty($parameter->getName())->getValue($consumer);
        }
        return $class->newInstanceArgs($arguments);
    }

    public function testFinderUsesEachQuotesStoreLifetimeRatherThanTheOldSevenDaySetting(): void
    {
        $longLived = $this->quote(30, 8 * 86400);
        $this->quote(2, 3 * 86400);
        $this->quote(30, 31 * 86400);
        $this->assertSame(1, $this->finder()->execute());
        $this->assertSame([$longLived], $this->published);
    }

    public function testSameAddressCartsAreBothQueuedOnceWithoutExtendingLifetime(): void
    {
        $first = $this->quote();
        $second = $this->quote();
        $updated = [$this->row($first)['updated_at'], $this->row($second)['updated_at']];
        $finder = $this->finder();
        $this->assertSame(2, $finder->execute());
        $this->assertSame(0, $finder->execute());
        $this->assertEqualsCanonicalizing([$first, $second], $this->published);
        $this->assertSame($updated, [$this->row($first)['updated_at'], $this->row($second)['updated_at']]);
    }

    /** @dataProvider publishFailureOutcomes */
    #[\PHPUnit\Framework\Attributes\DataProvider('publishFailureOutcomes')]
    public function testFailedPublishIsRecoveredWithoutASecondReminder(bool $acceptedBeforeFailure): void
    {
        $id = $this->quote();
        $before = $this->row($id);
        $this->publishFails = true;
        $this->acceptedBeforePublishFailure = $acceptedBeforeFailure;
        $finder = $this->finder();
        try {
            $finder->execute();
            $this->fail('A publish exception must reach Magento cron.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fixture queue failure', $error->getMessage());
        }
        $this->assertNull($this->row($id)['queued_at'], 'the mark is cleared at once');
        $this->assertNull($this->row($id)['reminded_at']);
        $this->assertFalse($this->cooldown()->isRecent($this->email));
        $this->assertSame($acceptedBeforeFailure ? [$id] : [], $this->published);

        $this->publishFails = false;
        $this->assertSame(1, $finder->execute(), 'the next run publishes it again');
        $this->assertSame($acceptedBeforeFailure ? [$id, $id] : [$id], $this->published);
        $this->assertSame($before['updated_at'], $this->row($id)['updated_at']);
        $this->assertSame($before['captured_at'], $this->row($id)['captured_at']);

        // If the broker accepted the first publish before reporting failure, both copies arrive: one email.
        $consumer = $this->consumer();
        foreach ($this->published as $quoteId) {
            $consumer->process($this->subscriptionOf($quoteId));
        }
        $this->assertSame(1, $this->sent);
        $this->assertNotNull($this->row($id)['reminded_at']);
        $this->assertSame(0, $finder->execute());
    }

    public function testSameAddressCartsAreDeferredWithoutBeingMarkedSentAndDuplicatesDoNotSendAgain(): void
    {
        $first = $this->quote();
        $second = $this->quote();
        $updated = $this->row($first)['updated_at'];
        $consumer = $this->consumer();
        foreach ([$first, $second, $first, $second] as $id) { $consumer->process($this->subscriptionOf($id)); }
        $this->assertSame(1, $this->sent);
        $this->assertNotNull($this->row($first)['reminded_at']);
        $this->assertNull($this->row($second)['reminded_at']);
        $this->assertNull($this->row($second)['queued_at']);
        $this->assertSame($updated, $this->row($first)['updated_at']);
    }

    public function testCooldownExpiresAndTheDeferredCartBecomesEligible(): void
    {
        $first = $this->quote();
        $second = $this->quote();
        $this->consumer()->process($this->subscriptionOf($first));
        $this->assertSame(0, $this->finder()->execute());
        $this->resource->getConnection()->update($this->table('ck_notification_cooldown'),
            ['sent_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)],
            ['recipient_hash = ?' => $this->cooldown()->hash($this->email)]);
        $this->assertSame(1, $this->finder()->execute());
        $this->assertSame([$second], $this->published);
        $this->consumer()->process($this->subscriptionOf($second));
        $this->assertSame(2, $this->sent);
    }

    public function testFailedSendReleasesRecipientAndCartClaims(): void
    {
        $id = $this->quote();
        $this->sendFails = true;
        try { $this->consumer()->process($this->subscriptionOf($id)); $this->fail('Expected fixture failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture SMTP failure', $e->getMessage()); }
        $this->assertFalse($this->cooldown()->isRecent($this->email));
        $this->assertNull($this->row($id)['reminded_at']);
        $this->sendFails = false;
        $this->consumer()->process($this->subscriptionOf($id));
        $this->assertSame(1, $this->sent);
    }

    public function testNormalizedRecipientsAndOwnedRelease(): void
    {
        $this->quote(); // Registers this fixture address for cleanup.
        $cooldown = $this->cooldown();
        $first = $cooldown->claim('  ' . strtoupper($this->email) . '  ');
        $this->assertNotNull($first);
        $this->assertNull($cooldown->claim($this->email));
        $cooldown->release($this->email, str_repeat('0', 32));
        $this->assertTrue($cooldown->isRecent($this->email));
        $this->resource->getConnection()->update($this->table('ck_notification_cooldown'),
            ['sent_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)],
            ['recipient_hash = ?' => $cooldown->hash($this->email)]);
        $replacement = $cooldown->claim($this->email);
        $this->assertNotNull($replacement);
        $this->assertNotSame($first, $replacement);
        $cooldown->release($this->email, $first);
        $this->assertTrue($cooldown->isRecent($this->email));
        $cooldown->release($this->email, $replacement);
        $this->assertFalse($cooldown->isRecent($this->email));
    }

    private function workers(string $mode, array $ids, int $count = 4): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'ck-reminder-barrier-');
        $workers = [];
        try {
            for ($number = 1; $number <= $count; $number++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', __DIR__ . '/reminder-worker.php',
                    $mode, (string)$this->storeIds[30], implode(',', $ids), $barrier, (string)$number],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BP);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 30;
            for ($number = 1; $number <= $count; $number++) {
                while (!is_file($barrier . '.' . $number . '.ready')) {
                    if (!proc_get_status($workers[$number - 1][0])['running']) {
                        $this->fail('Reminder worker exited before the barrier: '
                            . stream_get_contents($workers[$number - 1][1][2]));
                    }
                    if (microtime(true) > $deadline) { $this->fail('Reminder workers did not reach the barrier'); }
                    usleep(10000);
                }
            }
            file_put_contents($barrier . '.go', 'go');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $stderr . $stdout);
                $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            }
            $workers = [];
            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                    proc_close($process);
                }
            }
            foreach (glob($barrier . '*') as $file) { unlink($file); }
        }
    }

    public function testConcurrentFindersPublishEachCartOnlyOnce(): void
    {
        $ids = [];
        for ($i = 0; $i < 12; $i++) { $ids[] = $this->quote(); }
        $results = $this->workers('finder', $ids);
        $published = array_merge(...array_column($results, 'published'));
        $this->assertCount(12, $published);
        $this->assertEqualsCanonicalizing($ids, $published);
        foreach ($ids as $id) { $this->assertNotNull($this->row($id)['queued_at']); }
    }

    public function testConcurrentConsumersClaimEachCartOnlyOnce(): void
    {
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $id = $this->quote();
            $this->state($id, ['email' => $id . '-' . $this->email]);
            $ids[] = $id;
        }
        $results = $this->workers('consumer', $ids);
        $this->assertSame(12, array_sum(array_column($results, 'sent')));
        foreach ($ids as $id) { $this->assertNotNull($this->row($id)['reminded_at']); }
    }

    public function testConcurrentSameAddressConsumersSendOnlyOnce(): void
    {
        $ids = [];
        for ($i = 0; $i < 12; $i++) { $ids[] = $this->quote(); }
        $results = $this->workers('consumer', $ids);
        $this->assertSame(1, array_sum(array_column($results, 'sent')));
        $sent = array_filter($ids, fn ($id) => $this->row($id)['reminded_at'] !== null);
        $this->assertCount(1, $sent);
    }

    public function testInterruptedClaimIsNotAutomaticallyRetriedAsAnUnsentCart(): void
    {
        $id = $this->quote();
        $result = $this->workers('interrupt', [$id], 1);
        $this->assertTrue($result[0]['interrupted']);
        $this->assertNotNull($this->row($id)['reminded_at']);
        $this->assertSame(0, $this->workers('consumer', [$id], 1)[0]['sent']);
        $this->assertSame(0, $this->finder()->execute());
    }

    public function testDelayedMessagesCannotSendForExpiredOrRecentlyChangedQuotes(): void
    {
        $expired = $this->quote(2, 3 * 86400);
        $recent = $this->quote(30, 0);
        $consumer = $this->consumer();
        foreach ([$expired, $recent] as $id) { $consumer->process($this->subscriptionOf($id)); }
        $this->assertSame(0, $this->sent);
        $this->assertNull($this->row($expired)['reminded_at']);
        $this->assertNull($this->row($recent)['reminded_at']);
    }

    public function testRestoreStopsAtQuoteExpiryEvenBeforeMagentoDeletesTheRow(): void
    {
        $id = $this->quote(2, 3 * 86400);
        $this->state($id, ['reminded_at' => gmdate('Y-m-d H:i:s')]);
        $access = new RestoreAccess($this->om->get(LinkSignature::class),
            $this->createStub(\CommerceKings\Notification\Api\SubscriptionPermissionInterface::class), $this->stores, $this->lifetime(),
            $this->om->get(CartTable::class), $this->om->get(\CommerceKings\Notification\Api\SubscriptionRepositoryInterface::class));
        $quote = $this->om->get(CartRepositoryInterfaceFactory::class)->create()->get($id);
        $this->assertFalse($access->isAvailable($quote));
    }

    public function testFrozenCompatibilityPolicyRunsThroughTheRealFinderSql(): void
    {
        $this->policy = new \CommerceKings\AbandonedCart\Test\Unit\Fixture\CompatibleReminderPolicy();
        $id = $this->quote();
        // a stale queue claim also runs the release statement with the policy's condition
        $this->state($id, ['queued_at' => gmdate('Y-m-d H:i:s', time() - 7200)]);
        $this->assertSame(0, $this->finder()->execute(), 'quote ids are positive: never due, but valid SQL');
        $this->assertSame([], $this->published);
        $this->consumer()->process($this->subscriptionOf($id));
        $this->assertSame(0, $this->sent);
    }

    public function testHistoryPolicyCanUseTheCartIdInSelectionAndClaimSql(): void
    {
        $history = $this->table('ck_abandoned_cart');
        // a third-party policy with its own history subquery, keyed by the cart id; once per cart like the default
        $this->policy = new class($history) implements ReminderPolicyInterface {
            public function __construct(private readonly string $history) {}
            public function getDescription(): string { return 'history'; }
            public function canSend(\CommerceKings\AbandonedCart\Api\Data\ReminderState $state): bool
            {
                return $state->lastRemindedAt === null;
            }
            public function dueCondition(\CommerceKings\AbandonedCart\Api\Data\ReminderQuery $query): string
            {
                return sprintf('NOT EXISTS (SELECT 1 FROM %s AS h WHERE h.quote_id = %s AND h.reminded_at IS NOT NULL)'
                    . ' AND %s IS NULL', $this->history, $query->quoteId(), $query->column('reminded_at'));
            }
        };
        $id = $this->quote();
        $this->assertNull($this->row($id)['queued_at'], 'never queued: the send history row does not exist yet');
        $this->assertSame(1, $this->finder()->execute());
        $this->assertSame([$id], $this->published);
        $this->consumer()->process($this->subscriptionOf($id));
        $this->assertSame(1, $this->sent);
        $this->assertNotNull($this->row($id)['reminded_at']);
        $this->published = [];
        $this->assertSame(0, $this->finder()->execute());
    }

    public static function publishFailureOutcomes(): array
    {
        return ['not accepted' => [false], 'accepted but acknowledgement lost' => [true]];
    }

    public function testTheWholeBacklogIsQueuedAndASkipClearsItsMark(): void
    {
        [$first, $second, $third] = [$this->quote(), $this->quote(), $this->quote()];
        foreach ([$first, $second, $third] as $id) { $this->state($id, ['email' => $id . '-' . $this->email]); }
        $this->assertSame(3, $this->finder()->execute(), 'the queue holds the backlog');
        $this->assertSame(0, $this->finder()->execute(), 'marked carts are not published twice');

        // a cart that changed while its message waited is skipped; its mark is cleared so it can be found again
        $this->resource->getConnection()->update($this->table('quote'), ['updated_at' => gmdate('Y-m-d H:i:s')],
            ['entity_id = ?' => $second]);
        $consumer = $this->consumer();
        foreach ([$first, $second, $third] as $id) { $consumer->process($this->subscriptionOf($id)); }
        $this->assertSame(2, $this->sent);
        $this->assertNull($this->row($second)['queued_at'], 'the skip cleared the mark');
        $this->assertSame(0, $this->finder()->execute(), 'not due again until its delay has passed');
        $this->resource->getConnection()->update($this->table('quote'), ['updated_at' => gmdate('Y-m-d H:i:s', time() - 120)],
            ['entity_id = ?' => $second]);
        $this->assertSame(1, $this->finder()->execute());
        $consumer->process($this->subscriptionOf($second));
        $this->assertSame(3, $this->sent, 'every cart once');
    }

    public function testCartsStuckForADayAreQueuedAgainAndStillSendOnce(): void
    {
        $id = $this->quote();
        $this->assertSame(1, $this->finder()->execute());
        // a consumer crashed holding the message: the mark stays without a reminder
        $this->state($id, ['queued_at' => gmdate('Y-m-d H:i:s', time() - 25 * 3600)]);
        $this->assertSame(1, $this->finder()->execute(), 'found again after a day');
        $consumer = $this->consumer();
        foreach ($this->published as $quoteId) { $consumer->process($this->subscriptionOf($quoteId)); }
        $this->assertSame(1, $this->sent, 'the late original and the new copy send once');
    }

    public function testHourlyLimitHoldsWhenADelayedBacklogDrains(): void
    {
        // review c77e0cb: two messages reaching restarted consumers in the same hour must not both send
        $this->perHour = 1;
        [$first, $second] = [$this->quote(), $this->quote()];
        foreach ([$first, $second] as $id) { $this->state($id, ['email' => $id . '-' . $this->email]); }
        $this->assertSame(1, $this->finder()->execute(), 'the finder publishes only what fits in the hour');
        $this->assertSame(0, $this->finder()->execute(), 'the queued cart counts against the hour');
        // consumers were down for a day: the first message is still in the broker, its cart is found again
        $this->state($this->published[0], ['queued_at' => gmdate('Y-m-d H:i:s', time() - 25 * 3600)]);
        $this->assertSame(1, $this->finder()->execute());
        $consumer = $this->consumer();
        foreach ([$first, $second] as $id) { $consumer->process($this->subscriptionOf($id)); }
        $this->assertSame(1, $this->sent, 'the provider limit holds at send time');
    }

    public function testAWithdrawnQueuedCartDoesNotTakeAPlaceInTheHour(): void
    {
        // review 2d88801: its message is dropped unsent by Notification's gate, so its mark must not block others
        $this->perHour = 1;
        [$first, $second] = [$this->quote(), $this->quote()];
        foreach ([$first, $second] as $id) { $this->state($id, ['email' => $id . '-' . $this->email]); }
        $this->assertSame(1, $this->finder()->execute());
        $this->resource->getConnection()->delete($this->table(SubscriptionManagement::TABLE),
            ['type = ?' => CartReminder::CODE, 'reference_id = ?' => $this->published[0]]);
        $this->assertSame(1, $this->finder()->execute(), 'the other cart is queued; nothing was sent this hour');
    }

    public function testARecurringPolicyCanScheduleAnotherReminderAfterASuccessfulOne(): void
    {
        // review 2d88801: a sent cart kept its queued mark forever, so ReminderPolicyInterface could not recur
        $this->policy = new class implements ReminderPolicyInterface {
            public function getDescription(): string { return 'every two minutes'; }
            public function canSend(\CommerceKings\AbandonedCart\Api\Data\ReminderState $state): bool
            {
                return $state->lastRemindedAt === null || $state->lastRemindedAt < gmdate('Y-m-d H:i:s', time() - 120);
            }
            public function dueCondition(\CommerceKings\AbandonedCart\Api\Data\ReminderQuery $query): string
            {
                $column = $query->column('reminded_at');
                return "($column IS NULL OR $column < NOW() - INTERVAL 2 MINUTE)";
            }
        };
        $id = $this->quote();
        $this->assertSame(1, $this->finder()->execute());
        $this->consumer()->process($this->subscriptionOf($id));
        $this->assertSame(1, $this->sent);
        $this->assertNull($this->row($id)['queued_at'], 'sent: no longer waiting in the queue');
        $this->assertSame(0, $this->finder()->execute(), 'not due again yet');
        $this->state($id, ['reminded_at' => gmdate('Y-m-d H:i:s', time() - 180)]);
        // the recipient cooldown is separate from the policy: let it expire too
        $this->resource->getConnection()->delete($this->table('ck_notification_cooldown'),
            ['recipient_hash = ?' => $this->cooldown()->hash($this->email)]);
        $this->assertSame(1, $this->finder()->execute(), 'the policy schedules the next reminder');
        $this->consumer()->process($this->subscriptionOf($id));
        $this->assertSame(2, $this->sent);
    }

    public function testAnOldSkippedDeliveryCannotReleaseANewerReservation(): void
    {
        // review c77e0cb: release() did not check which mark the worker read
        $id = $this->quote();
        $this->assertSame(1, $this->finder()->execute());
        $db = $this->resource->getConnection();
        $db->update($this->table('ck_abandoned_cart'), ['queued_at' => gmdate('Y-m-d H:i:s', time() - 300)], ['quote_id = ?' => $id]);
        $db->update($this->table('quote'), ['updated_at' => gmdate('Y-m-d H:i:s')], ['entity_id = ?' => $id]);
        $consumer = $this->consumer();
        $original = (new \ReflectionProperty($consumer, 'recipients'))->getValue($consumer);
        $recipients = $this->createMock(ReminderEmailResolverInterface::class);
        $recipients->expects($this->once())->method('resolve')->willReturnCallback(function ($quoteId) use ($original, $db) {
            // meanwhile: the old mark is cleared, the cart ages, and a new finder run queues it again
            $db->update($this->table('ck_abandoned_cart'), ['queued_at' => null], ['quote_id = ?' => $quoteId]);
            $db->update($this->table('quote'), ['updated_at' => gmdate('Y-m-d H:i:s', time() - 120)], ['entity_id = ?' => $quoteId]);
            $this->assertSame(1, $this->finder()->execute());
            return $original->resolve($quoteId);
        });
        // this worker still holds its older 'recently updated' snapshot and skips
        $this->withRecipients($consumer, $recipients)->process($this->subscriptionOf($id));
        $this->assertNotNull($this->row($id)['queued_at'], 'the newer mark stays');
    }

    public function testAnOldDuplicateAndASameSecondRetrySendOnce(): void
    {
        // without queue tokens a same-second retry and an old duplicate share the mark's value:
        // the worst case is one more message, never a second email and never a lost cart
        $id = $this->quote();
        $db = $this->resource->getConnection();
        $db->query('SET timestamp = ' . time());
        try {
            $this->assertSame(1, $this->finder()->execute());
            $old = $this->row($id);
            // A claims delivery. Duplicate B reads that state and will skip it as already reminded.
            $this->state($id, ['reminded_at' => $old['queued_at']]);
            $consumer = $this->consumer();
            $original = (new \ReflectionProperty($consumer, 'recipients'))->getValue($consumer);
            $recipients = $this->createMock(ReminderEmailResolverInterface::class);
            $recipients->expects($this->once())->method('resolve')->willReturnCallback(function ($quoteId) use ($original) {
                // A fails before SMTP handoff. The next finder queues its retry in this same second.
                $this->state($quoteId, ['reminded_at' => null, 'queued_at' => null]);
                $this->assertSame(1, $this->finder()->execute());
                return $original->resolve($quoteId);
            });
            $this->withRecipients($consumer, $recipients)->process($this->subscriptionOf($id));
            $this->assertSame(0, $this->sent);
            $this->finder()->execute(); // an extra copy may be published if B cleared the retry's mark
            foreach ($this->published as $quoteId) { $this->consumer()->process($this->subscriptionOf($quoteId)); }
            $this->assertSame(1, $this->sent, 'one email');
            $this->assertNotNull($this->row($id)['reminded_at'], 'and not lost');
        } finally {
            $db->query('SET timestamp = 0');
        }
    }

    public function testAnObsoleteWorkerAndAReplacementSendOnce(): void
    {
        $id = $this->quote();
        $this->assertSame(1, $this->finder()->execute());
        $consumer = $this->consumer();
        $original = (new \ReflectionProperty($consumer, 'recipients'))->getValue($consumer);
        $raced = false;
        $recipients = $this->createStub(ReminderEmailResolverInterface::class);
        $recipients->method('resolve')->willReturnCallback(function ($quoteId) use ($original, &$raced) {
            if (!$raced) { // while this worker checks: the mark is cleared and the cart queued again
                $raced = true;
                $this->state($quoteId, ['queued_at' => null]);
                $this->assertSame(1, $this->finder()->execute());
            }
            return $original->resolve($quoteId);
        });
        $this->withRecipients($consumer, $recipients)->process($this->subscriptionOf($id));
        $this->consumer()->process($this->subscriptionOf($id));
        $this->assertSame(1, $this->sent, 'whichever wins the claim sends; the other skips');
        $this->assertNotNull($this->row($id)['reminded_at']);
    }

    public function testStreamingFinderDoesNotSkipCartsWhenClaimingRemovesEarlierRows(): void
    {
        $ids = array_map(fn () => $this->quote(), range(1, 201));
        $this->assertSame(201, $this->finder()->execute());
        $this->assertEqualsCanonicalizing($ids, $this->published);
        $this->assertCount(201, array_unique($this->published));
        $this->assertSame(0, $this->finder()->execute(), 'every cart is marked');
    }

    public function testAdminPreviewIsReadOnlyAndUsesTheSelectedStoreScope(): void
    {
        $form = $this->om->get(FormFactory::class)->create();
        $element = $form->addField('quote_lifetime', 'label', ['label' => 'Magento quote lifetime',
            'scope' => 'stores', 'scope_id' => $this->storeIds[2], 'can_use_default_value' => true]);
        $block = $this->om->create(LifetimeField::class, ['lifetime' => $this->lifetime()]);
        $html = $block->render($element);
        $this->assertStringContainsString('2 days', $html);
        $this->assertStringNotContainsString('<input', $html);

        $element = $form->addField('reminders_per_cart', 'label', ['label' => 'Reminders per cart',
            'can_use_default_value' => true, 'can_use_website_value' => true, 'can_restore_to_default' => true]);
        $block = $this->om->create(PolicyField::class);
        $html = $block->render($element);
        $this->assertStringContainsString('1 (one-time)', $html);
        $this->assertStringNotContainsString('<input', $html);

        // A replaced policy supplies its own description; the renderer escapes it and stays read-only.
        $policy = $this->createStub(ReminderPolicyInterface::class);
        $policy->method('getDescription')->willReturn('<b>Custom frequency</b>');
        $html = $this->om->create(PolicyField::class, ['policy' => $policy])->render($element);
        $this->assertStringContainsString('&lt;b&gt;Custom frequency&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Custom frequency</b>', $html);
        $this->assertStringNotContainsString('<input', $html);
    }

    public function testSubscriptionActivityPostponesQueueingAndSendingWithoutExtendingQuoteLifetime(): void
    {
        $id = $this->quote(30, 120);
        $this->state($id, ['captured_at' => gmdate('Y-m-d H:i:s')]);
        $updated = $this->row($id)['updated_at'];
        $this->assertSame(0, $this->finder()->execute());
        $this->consumer()->process($this->subscriptionOf($id));
        $this->assertSame(0, $this->sent);
        $this->assertSame($updated, $this->row($id)['updated_at']);
    }
}
