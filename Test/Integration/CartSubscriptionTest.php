<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Integration;

use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\AbandonedCart\Model\Notification\CartSubscription;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Consent\Api\Data\WithdrawalContext;
use CommerceKings\Consent\Api\WithdrawalHandlerInterface;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\WithdrawalPool;
use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\Consent\ConsentPermission;
use CommerceKings\Notification\Model\SubscriptionManagement;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\Validator\StringLength;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real SQL: a cart subscribed through CommerceKings_Notification with this module's CartSubscription type.
 * Consent, its SQL guard and the browser are stubbed; empty development quotes never become eligible for email.
 */
class CartSubscriptionTest extends TestCase
{
    private ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private StoreManagerInterface $stores;
    private array $quotes = [];
    private array $customers = [];
    private int $foreignStore;
    private int $foreignGroup;
    private int $foreignWebsite;

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(State::class)->setAreaCode('frontend');
        $this->resource = $this->om->get(ResourceConnection::class);
        $this->stores = $this->om->get(StoreManagerInterface::class);
        $this->stores->setCurrentStore('default');
        $code = 'ck-capture-' . bin2hex(random_bytes(6));
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $db->insert($this->table('store_website'), ['code' => $code, 'name' => 'Capture test', 'is_default' => 0]);
            $this->foreignWebsite = (int)$db->lastInsertId($this->table('store_website'));
            $db->insert($this->table('store_group'), ['code' => $code, 'website_id' => $this->foreignWebsite,
                'name' => 'Capture test', 'root_category_id' => 2, 'default_store_id' => 0]);
            $this->foreignGroup = (int)$db->lastInsertId($this->table('store_group'));
            $db->insert($this->table('store'), ['code' => $code, 'website_id' => $this->foreignWebsite,
                'group_id' => $this->foreignGroup, 'name' => 'Capture test', 'is_active' => 1]);
            $this->foreignStore = (int)$db->lastInsertId($this->table('store'));
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        $this->stores->reinitStores();
        $this->stores->setCurrentStore('default');
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        if ($this->quotes) {
            // contacts this test's subscriptions point to (fixture and subscribed addresses)
            $contacts = $db->fetchCol($db->select()->from($this->table(SubscriptionManagement::TABLE), 'contact_id')
                ->where('type = ?', CartReminder::CODE)->where('reference_id IN (?)', $this->quotes)
                ->where('contact_id IS NOT NULL'));
            $db->delete($this->table(SubscriptionManagement::TABLE),
                ['type = ?' => CartReminder::CODE, 'reference_id IN (?)' => $this->quotes]);
            $db->delete($this->table('quote'), ['entity_id IN (?)' => $this->quotes]);
            $contacts = array_merge($contacts, $db->fetchCol($db->select()->from($this->table(ContactRepository::TABLE), 'contact_id')
                ->where('email IN (?)', ['captured@example.test', 'new@example.test', 'changed@example.test'])));
            if ($contacts) { $db->delete($this->table(ContactRepository::TABLE), ['contact_id IN (?)' => $contacts]); }
        }
        $registry = $this->om->get(Registry::class);
        $secureArea = $registry->registry('isSecureArea');
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        try {
            foreach ($this->customers as $id) { $this->om->get(CustomerRepositoryInterface::class)->deleteById($id); }
        } finally {
            $registry->unregister('isSecureArea');
            if ($secureArea !== null) { $registry->register('isSecureArea', $secureArea); }
            $db->delete($this->table('store'), ['store_id = ?' => $this->foreignStore]);
            $db->delete($this->table('store_group'), ['group_id = ?' => $this->foreignGroup]);
            $db->delete($this->table('store_website'), ['website_id = ?' => $this->foreignWebsite]);
        }
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /** A guest cart subscribed with captured@example.test under consent "original". */
    private function quote(bool $foreign = false): array
    {
        $quote = $this->om->get(QuoteFactory::class)->create();
        $quote->setStore($this->stores->getStore())->setIsActive(true)->setCustomerIsGuest(true);
        $this->om->get(CartRepositoryInterface::class)->save($quote);
        $id = (int)$quote->getId();
        $this->quotes[] = $id;
        $mask = $this->om->get(QuoteIdMaskFactory::class)->create()->setQuoteId($id);
        $this->om->get(QuoteIdMask::class)->save($mask);
        $storeId = $foreign ? $this->foreignStore : (int)$this->stores->getStore()->getId();
        $this->resource->getConnection()->update($this->table('quote'), [
            'store_id' => $storeId,
            'customer_email' => 'fixture@example.test',
        ], ['entity_id = ?' => $id]);
        $this->subscribe($id, $storeId, 'captured@example.test');
        return [$id, (string)$mask->getMaskedId()];
    }

    private function subscribe(int $id, int $storeId, ?string $email, ?int $customerId = null, string $consentId = 'original'): void
    {
        $contactId = $email === null ? null : $this->om->get(ContactRepositoryInterface::class)
            ->save((int)$this->stores->getStore($storeId)->getWebsiteId(), $email);
        $this->resource->getConnection()->insertOnDuplicate($this->table(SubscriptionManagement::TABLE), [
            'type' => CartReminder::CODE, 'reference_id' => $id, 'store_id' => $storeId, 'subject_key' => '',
            'contact_id' => $contactId, 'customer_id' => $customerId, 'consent_id' => $consentId,
            'captured_at' => '2026-01-01 00:00:00',
        ]);
    }

    /** The quote with its subscription, contact email and send history (null fields when there is none). */
    private function row(int $id): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()
            ->from(['q' => $this->table('quote')], ['store_id', 'customer_email', 'updated_at'])
            ->joinLeft(['s' => $this->table(SubscriptionManagement::TABLE)],
                $db->quoteInto('s.type = ? AND s.reference_id = q.entity_id', CartReminder::CODE),
                ['captured_at', 'consent_id', 'customer_id'])
            ->joinLeft(['c' => $this->table(CartTable::TABLE)], 'c.quote_id = q.entity_id', ['reminded_at'])
            ->joinLeft(['n' => $this->table(ContactRepository::TABLE)], 'n.contact_id = s.contact_id', ['email'])
            ->where('q.entity_id = ?', $id));
    }

    private function consent(int $website = 0, string $purpose = Config::PURPOSE): ConsentInterface
    {
        $consent = $this->createStub(ConsentInterface::class);
        $consent->method('getCurrent')->willReturn(new Consent('new-consent', $purpose,
            $website ?: (int)$this->stores->getStore()->getWebsiteId(), 'source', 'choice', '2026-01-01 00:00:00',
            '1', '1', '2026-01-01 00:00:00', '2027-01-01 00:00:00'));
        return $consent;
    }

    private function management(?ConsentInterface $consent = null, ?QuoteScope $scope = null,
        bool $enabled = true, bool $guardValid = true): SubscriptionManagement
    {
        $guard = $this->createStub(ConsentGuardInterface::class);
        $guard->method('validCondition')->willReturn($guardValid ? '1 = 1' : '0 = 1');
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $type = new CartSubscription($this->om->get(QuoteIdMaskFactory::class), $this->om->get(QuoteIdMask::class),
            $scope ?? $this->om->get(QuoteScope::class), $config, $this->resource);
        return new SubscriptionManagement($this->resource, new SubscriptionTypePool([CartReminder::CODE => $type]),
            new ConsentPermission($consent ?? $this->consent(), $guard, $this->stores,
                $this->createStub(BrowserIdentityInterface::class), $this->createStub(ConsentStore::class)),
            $this->om->get(ContactRepositoryInterface::class), $this->stores,
            new EmailAddress(), new StringLength(['max' => 255]), $this->om->create(FilterManager::class));
    }

    private function customer(): int
    {
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create();
        $customer->setWebsiteId((int)$this->stores->getStore()->getWebsiteId())->setStoreId((int)$this->stores->getStore()->getId())
            ->setEmail('ck-subscription-' . bin2hex(random_bytes(6)) . '@example.test')->setFirstname('Fixture')->setLastname('Recipient');
        $id = (int)$this->om->get(CustomerRepositoryInterface::class)->save($customer)->getId();
        $this->customers[] = $id;
        return $id;
    }

    public function testAnotherWebsiteCannotSubscribeOrUnsubscribeEvenWithItsMaskedKey(): void
    {
        [$id, $mask] = $this->quote(true);
        $before = $this->row($id);
        $consent = $this->createMock(ConsentInterface::class);
        $consent->expects($this->never())->method('getCurrent');
        $management = $this->management($consent);
        $this->assertFalse($management->subscribeGuest(CartReminder::CODE, $mask, 'new@example.test'));
        $this->assertFalse($management->subscribeGuest(CartReminder::CODE, $mask, ''));
        $this->assertSame($before, $this->row($id));
    }

    public function testOwnWebsiteCanSubscribeAcknowledgeRetryAndUnsubscribe(): void
    {
        [$id, $mask] = $this->quote();
        $nativeUpdatedAt = $this->row($id)['updated_at'];
        $management = $this->management();
        $this->assertTrue($management->subscribeGuest(CartReminder::CODE, $mask, 'new@example.test'));
        $this->assertTrue($management->subscribeGuest(CartReminder::CODE, $mask, 'new@example.test'));
        $this->assertSame('new-consent', $this->row($id)['consent_id']);
        $this->assertSame('new@example.test', $this->row($id)['email']);
        $this->assertSame('fixture@example.test', $this->row($id)['customer_email']);
        $this->assertSame($nativeUpdatedAt, $this->row($id)['updated_at'], 'Magento\'s quote is not written');
        $this->assertFalse($management->subscribeGuest(CartReminder::CODE, $mask, ''));
        $this->assertNull($this->row($id)['email']);
        $this->assertNull($this->row($id)['consent_id']);
        $this->assertSame('fixture@example.test', $this->row($id)['customer_email']);
    }

    public function testDisabledStoreUnsubscribesTheGuest(): void
    {
        [$id, $mask] = $this->quote();
        $this->assertFalse($this->management(enabled: false)->subscribeGuest(CartReminder::CODE, $mask, 'new@example.test'));
        $this->assertNull($this->row($id)['consent_id']);
    }

    public function testStoreChangeAfterAuthorizationCannotWriteOrRemove(): void
    {
        [$id, $mask] = $this->quote();
        $storeId = (int)$this->stores->getStore()->getId();
        $scope = $this->createStub(QuoteScope::class);
        $scope->method('find')->willReturnCallback(function () use ($id, $storeId) {
            $this->resource->getConnection()->update($this->table('quote'), ['store_id' => $this->foreignStore], ['entity_id = ?' => $id]);
            return ['store_id' => $storeId];
        });
        $this->assertFalse($this->management(scope: $scope)->subscribeGuest(CartReminder::CODE, $mask, 'new@example.test'));
        $this->assertSame('original', $this->row($id)['consent_id']);
        $this->assertFalse($this->management(scope: $scope, enabled: false)->subscribeGuest(CartReminder::CODE, $mask, ''));
        $this->assertSame('original', $this->row($id)['consent_id']);
    }

    public function testWrongConsentWebsiteAndPurposeDoNotChangeTheCart(): void
    {
        [$id, $mask] = $this->quote();
        $before = $this->row($id);
        $this->assertFalse($this->management($this->consent($this->foreignWebsite))
            ->subscribeGuest(CartReminder::CODE, $mask, 'new@example.test'));
        $this->assertFalse($this->management($this->consent(purpose: 'analytics'))
            ->subscribeGuest(CartReminder::CODE, $mask, 'new@example.test'));
        $this->assertSame($before, $this->row($id));
    }

    public function testAtomicGuardRefusalAndStaleOwnershipAreNotAcknowledgedAsStored(): void
    {
        [$id, $mask] = $this->quote();
        $before = $this->row($id);
        $this->assertFalse($this->management(guardValid: false)->subscribeGuest(CartReminder::CODE, $mask, 'new@example.test'));
        $scope = $this->createStub(QuoteScope::class);
        // Model a stale authorization read: the quote no longer belongs to this customer.
        $scope->method('find')->willReturn(['store_id' => (int)$this->stores->getStore()->getId()]);
        $this->assertFalse($this->management(scope: $scope)->subscribeCustomer(123, CartReminder::CODE, (string)$id));
        $this->assertSame($before, $this->row($id));
    }

    public function testCustomerSubscribesOnlyTheirOwnCart(): void
    {
        [$id] = $this->quote();
        $this->resource->getConnection()->delete($this->table(SubscriptionManagement::TABLE),
            ['type = ?' => CartReminder::CODE, 'reference_id = ?' => $id]);
        $owner = $this->customer();
        $this->resource->getConnection()->update($this->table('quote'), ['customer_id' => $owner], ['entity_id = ?' => $id]);
        $management = $this->management();
        $this->assertFalse($management->subscribeCustomer($this->customer(), CartReminder::CODE, (string)$id));
        $this->assertNull($this->row($id)['consent_id']);
        $this->assertTrue($management->subscribeCustomer($owner, CartReminder::CODE, (string)$id));
        $this->assertSame((string)$owner, $this->row($id)['customer_id']);
        $this->assertNull($this->row($id)['email'], 'customers use their account email, not a contact');
        $this->assertSame('fixture@example.test', $this->om->get(ReminderEmailResolverInterface::class)->resolve($id));
    }

    public function testEmailChangesHaveTheirOwnClockAndDoNotResetTheReminder(): void
    {
        [$id, $mask] = $this->quote();
        $db = $this->resource->getConnection();
        $db->update($this->table('quote'), ['updated_at' => '2026-01-01 00:00:00'], ['entity_id = ?' => $id]);
        $carts = $this->om->get(CartTable::class);
        $carts->ensure($id);
        $db->update($carts->name(), ['reminded_at' => '2026-01-01 00:00:00'], ['quote_id = ?' => $id]);
        $management = $this->management();
        $this->assertTrue($management->subscribeGuest(CartReminder::CODE, $mask, 'captured@example.test'));
        $before = $this->row($id);
        $this->assertTrue($management->subscribeGuest(CartReminder::CODE, $mask, 'captured@example.test'));
        $this->assertSame($before['captured_at'], $this->row($id)['captured_at'], 'an identical retry keeps the clock');
        $db->update($this->table(SubscriptionManagement::TABLE), ['captured_at' => '2026-01-01 00:00:00'],
            ['type = ?' => CartReminder::CODE, 'reference_id = ?' => $id]);
        $this->assertTrue($management->subscribeGuest(CartReminder::CODE, $mask, 'changed@example.test'));
        $changed = $this->row($id);
        $this->assertGreaterThan('2026-01-01 00:00:00', $changed['captured_at']);
        $this->assertSame($before['updated_at'], $changed['updated_at']);
        $this->assertSame($before['customer_email'], $changed['customer_email']);
        $this->assertSame('2026-01-01 00:00:00', $changed['reminded_at'], 'the send history stays');
    }

    public function testGuestResolverUsesOnlyTheSubscribedRecipient(): void
    {
        [$id, $mask] = $this->quote();
        $resolver = $this->om->get(ReminderEmailResolverInterface::class);
        $this->assertSame('captured@example.test', $resolver->resolve($id));
        $this->management()->subscribeGuest(CartReminder::CODE, $mask, '');
        $this->assertNull($resolver->resolve($id));
        $this->assertSame('fixture@example.test', $this->row($id)['customer_email']);
    }

    public function testDeletedCartLosesItsSubscriptionAndThenTheGuestAddress(): void
    {
        [$deleted] = $this->quote();
        [$kept] = $this->quote();
        $db = $this->resource->getConnection();
        $contact = (int)$db->fetchOne($db->select()->from($this->table(SubscriptionManagement::TABLE), 'contact_id')
            ->where('type = ?', CartReminder::CODE)->where('reference_id = ?', $deleted));
        $db->update($this->table(SubscriptionManagement::TABLE), ['contact_id' => $this->om->get(ContactRepositoryInterface::class)
            ->save((int)$this->stores->getStore()->getWebsiteId(), 'changed@example.test')], [
                'type = ?' => CartReminder::CODE, 'reference_id = ?' => $kept]);
        // Magento's own quote cleanup (sales_clean_quotes) deletes through the repository
        $this->om->get(CartRepositoryInterface::class)->delete($this->om->get(CartRepositoryInterface::class)->get($deleted));
        $this->om->get(\CommerceKings\Notification\Cron\CleanUpSubscriptions::class)->execute();
        $this->assertSame([], $db->fetchCol($db->select()->from($this->table(SubscriptionManagement::TABLE), 'subscription_id')
            ->where('type = ?', CartReminder::CODE)->where('reference_id = ?', $deleted)));
        $this->assertSame('changed@example.test', $this->row($kept)['email'], 'other carts keep theirs');
        // the address is no longer used: the contact cleanup may remove it after its grace period
        $used = array_map(fn ($reference) => '(' . $reference->usedCondition('n.contact_id') . ')',
            $this->om->get(\CommerceKings\Notification\Model\ContactReferencePool::class)->getAll());
        $this->assertSame([(string)$contact], $db->fetchCol($db->select()->from(['n' => $this->table(ContactRepository::TABLE)], 'contact_id')
            ->where('n.contact_id = ?', $contact)->where('NOT (' . implode(' OR ', $used) . ')')));
    }

    public function testRegisteredWithdrawalRemovesGuestAndCustomerSubscriptionsButKeepsHistoryAndNewGrants(): void
    {
        [$guest] = $this->quote();
        [$registered] = $this->quote();
        [$newGrant] = $this->quote();
        $customer = $this->customer();
        $db = $this->resource->getConnection();
        $oldConsent = bin2hex(random_bytes(16));
        $storeId = (int)$this->stores->getStore()->getId();
        $this->subscribe($guest, $storeId, 'captured@example.test', null, $oldConsent);
        $db->update($this->table('quote'), ['customer_id' => $customer], ['entity_id = ?' => $registered]);
        $db->delete($this->table(SubscriptionManagement::TABLE), ['type = ?' => CartReminder::CODE, 'reference_id = ?' => $registered]);
        $this->subscribe($registered, $storeId, null, $customer, $oldConsent);
        $carts = $this->om->get(CartTable::class);
        foreach ([$guest, $registered] as $id) {
            $carts->ensure($id);
            $db->update($carts->name(), ['reminded_at' => '2026-01-01 00:00:00'], ['quote_id = ?' => $id]);
        }
        $before = [$guest => $this->row($guest), $registered => $this->row($registered)];
        $newBefore = $this->row($newGrant);
        $accountEmail = $this->om->get(CustomerRepositoryInterface::class)->getById($customer)->getEmail();
        $handler = $this->om->get(WithdrawalPool::class)->get(Config::PURPOSE, 'notification_subscriptions');
        $this->assertInstanceOf(WithdrawalHandlerInterface::class, $handler);
        $context = new WithdrawalContext(Config::PURPOSE, [$oldConsent], (int)$this->stores->getStore()->getWebsiteId(),
            'original-browser', 'email');
        $handler->execute($context);
        $handler->execute($context); // At-least-once retry is harmless.
        foreach ([$guest, $registered] as $id) {
            $after = $this->row($id);
            foreach (['email', 'captured_at', 'consent_id'] as $field) {
                $this->assertNull($after[$field]);
            }
            foreach (['customer_email', 'updated_at', 'reminded_at'] as $field) {
                $this->assertSame($before[$id][$field], $after[$field]);
            }
        }
        $this->assertSame($newBefore, $this->row($newGrant));
        $this->assertSame($accountEmail, $this->om->get(CustomerRepositoryInterface::class)->getById($customer)->getEmail());
    }
}
