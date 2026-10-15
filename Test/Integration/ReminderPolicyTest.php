<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Integration;

use CommerceKings\AbandonedCart\Block\Adminhtml\System\Config\QuoteLifetime as LifetimeField;
use CommerceKings\AbandonedCart\Block\Adminhtml\System\Config\ReminderPolicy as PolicyField;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Cron\FindAbandonedCarts;
use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\AbandonedCart\Model\RestoreAccess;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
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
    private int $sent = 0;
    private string $email;
    private bool $sendFails = false;

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
            $emails = $db->fetchCol($db->select()->from(['c' => $this->table('ck_abandoned_cart')], [])
                ->join(['n' => $this->table('ck_notification_contact')], 'n.contact_id = c.contact_id', 'email')
                ->where('c.quote_id IN (?)', $this->quoteIds));
            $hashes = array_map(fn ($email) => $this->cooldown()->hash($email), array_filter($emails));
            if ($hashes) { $db->delete($this->table('ck_notification_cooldown'), ['recipient_hash IN (?)' => $hashes]); }
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
        $this->resource->getConnection()->update($this->table('quote'), ['store_id' => $this->storeIds[$days],
            'items_count' => 1, 'updated_at' => gmdate('Y-m-d H:i:s', time() - $ageSeconds)], ['entity_id = ?' => $id]);
        $this->state($id, ['email' => $this->email, 'consent_id' => 'fixture-consent']);
        return $id;
    }

    /** Write the cart's reminder state; "email" becomes the guest contact of the cart's website. */
    private function state(int $id, array $values): void
    {
        if (isset($values['email'])) {
            $storeId = (int)$this->resource->getConnection()->fetchOne($this->resource->getConnection()->select()
                ->from($this->table('quote'), 'store_id')->where('entity_id = ?', $id));
            $values['contact_id'] = $this->om->get(\CommerceKings\Notification\Api\ContactRepositoryInterface::class)
                ->save((int)$this->stores->getStore($storeId)->getWebsiteId(), $values['email']);
            unset($values['email']);
        }
        $carts = $this->om->get(CartTable::class);
        $carts->ensure($id);
        $this->resource->getConnection()->update($carts->name(), $values, ['quote_id = ?' => $id]);
    }

    /** The quote with its reminder state and guest email. */
    private function row(int $id): array
    {
        return $this->resource->getConnection()->fetchRow($this->resource->getConnection()->select()
            ->from(['q' => $this->table('quote')])
            ->joinLeft(['c' => $this->table('ck_abandoned_cart')], 'c.quote_id = q.entity_id',
                ['contact_id', 'consent_id', 'browser_hash', 'captured_at', 'queued_at', 'reminded_at'])
            ->joinLeft(['n' => $this->table('ck_notification_contact')], 'n.contact_id = c.contact_id', ['email'])
            ->where('q.entity_id = ?', $id));
    }

    private function config(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($id) => in_array($id, $this->storeIds, true));
        $config->method('getDelayMinutes')->willReturn(1);
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
        $queue->method('publish')->willReturnCallback(function ($type, $id) { $this->published[] = (int)$id; });
        return new FindAbandonedCarts($this->config(), $this->resource, $this->guard(), $queue,
            new OncePerCart(), $this->lifetime(), $stores, $this->createStub(LoggerInterface::class),
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
            new OncePerCart(), $this->createStub(LoggerInterface::class), $this->om->get(QuoteScope::class), $this->lifetime(),
            $this->om->get(ReminderEmailResolverInterface::class),
            $this->createStub(\CommerceKings\Consent\Model\Config::class), $this->om->get(CartTable::class));
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

    public function testSameAddressCartsAreDeferredWithoutBeingMarkedSentAndDuplicatesDoNotSendAgain(): void
    {
        $first = $this->quote();
        $second = $this->quote();
        $updated = $this->row($first)['updated_at'];
        $consumer = $this->consumer();
        foreach ([$first, $second, $first, $second] as $id) { $consumer->process((string)$id); }
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
        $this->consumer()->process((string)$first);
        $this->assertSame(0, $this->finder()->execute());
        $this->resource->getConnection()->update($this->table('ck_notification_cooldown'),
            ['sent_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)],
            ['recipient_hash = ?' => $this->cooldown()->hash($this->email)]);
        $this->assertSame(1, $this->finder()->execute());
        $this->assertSame([$second], $this->published);
        $this->consumer()->process((string)$second);
        $this->assertSame(2, $this->sent);
    }

    public function testFailedSendReleasesRecipientAndCartClaims(): void
    {
        $id = $this->quote();
        $this->sendFails = true;
        try { $this->consumer()->process((string)$id); $this->fail('Expected fixture failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture SMTP failure', $e->getMessage()); }
        $this->assertFalse($this->cooldown()->isRecent($this->email));
        $this->assertNull($this->row($id)['reminded_at']);
        $this->sendFails = false;
        $this->consumer()->process((string)$id);
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
        foreach ([$expired, $recent] as $id) { $consumer->process((string)$id); }
        $this->assertSame(0, $this->sent);
        $this->assertNull($this->row($expired)['reminded_at']);
        $this->assertNull($this->row($recent)['reminded_at']);
    }

    public function testRestoreStopsAtQuoteExpiryEvenBeforeMagentoDeletesTheRow(): void
    {
        $id = $this->quote(2, 3 * 86400);
        $this->state($id, ['reminded_at' => gmdate('Y-m-d H:i:s')]);
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $access = new RestoreAccess($this->om->get(LinkSignature::class), $browser, $this->stores, $this->lifetime(),
            $this->om->get(CartTable::class));
        $quote = $this->om->get(CartRepositoryInterfaceFactory::class)->create()->get($id);
        $this->assertFalse($access->isAvailable($quote));
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

    public function testGuestEmailActivityPostponesQueueingAndSendingWithoutExtendingQuoteLifetime(): void
    {
        $id = $this->quote(30, 120);
        $this->state($id, ['captured_at' => gmdate('Y-m-d H:i:s')]);
        $updated = $this->row($id)['updated_at'];
        $this->assertSame(0, $this->finder()->execute());
        $this->consumer()->process((string)$id);
        $this->assertSame(0, $this->sent);
        $this->assertSame($updated, $this->row($id)['updated_at']);
    }
}
