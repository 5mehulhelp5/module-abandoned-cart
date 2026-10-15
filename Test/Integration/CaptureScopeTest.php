<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Integration;

use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\ConsentWithdrawal;
use CommerceKings\AbandonedCart\Model\CustomerConsentCapture;
use CommerceKings\AbandonedCart\Model\GuestEmailCapture;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/** Real SQL regressions; empty development quotes never become eligible for email. */
class CaptureScopeTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        if ($this->quotes) {
            // contacts this test's carts point to (fixture and captured addresses)
            $contacts = $db->fetchCol($db->select()->from($this->table('ck_abandoned_cart'), 'contact_id')
                ->where('quote_id IN (?)', $this->quotes)->where('contact_id IS NOT NULL'));
            $db->delete($this->table('quote'), ['entity_id IN (?)' => $this->quotes]);
            if ($contacts) { $db->delete($this->table('ck_notification_contact'), ['contact_id IN (?)' => $contacts]); }
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

    private function quote(bool $foreign = false): array
    {
        $quote = $this->om->get(QuoteFactory::class)->create();
        $quote->setStore($this->stores->getStore())->setIsActive(true)->setCustomerIsGuest(true);
        $this->om->get(CartRepositoryInterface::class)->save($quote);
        $id = (int)$quote->getId();
        $this->quotes[] = $id;
        $mask = $this->om->get(QuoteIdMaskFactory::class)->create()->setQuoteId($id);
        $this->om->get(QuoteIdMask::class)->save($mask);
        $this->resource->getConnection()->update($this->table('quote'), [
            'store_id' => $foreign ? $this->foreignStore : (int)$this->stores->getStore()->getId(),
            'customer_email' => 'fixture@example.test',
        ], ['entity_id = ?' => $id]);
        $this->state($id, ['email' => 'captured@example.test', 'consent_id' => 'original',
            'captured_at' => '2026-01-01 00:00:00', 'browser_hash' => 'original-browser']);
        return [$id, (string)$mask->getMaskedId()];
    }

    /** Write the cart's reminder state; "email" becomes the guest contact of the cart's website. */
    private function state(int $id, array $values): void
    {
        if (isset($values['email'])) {
            $storeId = (int)$this->resource->getConnection()->fetchOne($this->resource->getConnection()->select()
                ->from($this->table('quote'), 'store_id')->where('entity_id = ?', $id));
            $this->stores->reinitStores();
            $values['contact_id'] = $this->om->get(ContactRepositoryInterface::class)
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
            ->from(['q' => $this->table('quote')], ['store_id', 'customer_email', 'updated_at'])
            ->joinLeft(['c' => $this->table('ck_abandoned_cart')], 'c.quote_id = q.entity_id',
                ['captured_at', 'consent_id', 'browser_hash', 'reminded_at'])
            ->joinLeft(['n' => $this->table('ck_notification_contact')], 'n.contact_id = c.contact_id', ['email'])
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

    private function dependencies(bool $enabled = true, bool $guardValid = true): array
    {
        $guard = $this->createStub(ConsentGuardInterface::class);
        $guard->method('validCondition')->willReturn($guardValid ? '1 = 1' : '0 = 1');
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturn('new-browser');
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        return [$guard, $browser, $config];
    }

    private function guest(?ConsentInterface $consent = null, ?QuoteScope $scope = null,
        bool $enabled = true, bool $guardValid = true): GuestEmailCapture
    {
        [$guard, $browser, $config] = $this->dependencies($enabled, $guardValid);
        return new GuestEmailCapture($this->om->get(QuoteIdMaskFactory::class), $this->om->get(QuoteIdMask::class),
            $this->resource, $consent ?? $this->consent(), $guard, $browser,
            $this->om->get(ConsentWithdrawal::class), $config, $scope ?? $this->om->get(QuoteScope::class),
            $this->om->get(CartTable::class), $this->om->get(ContactRepositoryInterface::class), $this->stores);
    }

    public function testAnotherWebsiteCannotCaptureOrClearEvenWithItsMaskedKey(): void
    {
        [$id, $mask] = $this->quote(true);
        $before = $this->row($id);
        $consent = $this->createMock(ConsentInterface::class);
        $consent->expects($this->never())->method('getCurrent');
        $guest = $this->guest($consent);
        $this->assertFalse($guest->capture($mask, 'new@example.test'));
        $this->assertFalse($guest->capture($mask, ''));
        $this->assertSame($before, $this->row($id));
    }

    public function testOwnWebsiteCanCaptureAcknowledgeRetryAndClear(): void
    {
        [$id, $mask] = $this->quote();
        $nativeUpdatedAt = $this->row($id)['updated_at'];
        $guest = $this->guest();
        $this->assertTrue($guest->capture($mask, 'new@example.test'));
        $this->assertTrue($guest->capture($mask, 'new@example.test'));
        $this->assertSame('new-consent', $this->row($id)['consent_id']);
        $this->assertSame('new@example.test', $this->row($id)['email']);
        $this->assertSame('fixture@example.test', $this->row($id)['customer_email']);
        $this->assertSame($nativeUpdatedAt, $this->row($id)['updated_at']);
        $this->assertFalse($guest->capture($mask, ''));
        $this->assertNull($this->row($id)['email']);
        $this->assertNull($this->row($id)['captured_at']);
        $this->assertSame('fixture@example.test', $this->row($id)['customer_email']);
        $this->assertNull($this->row($id)['consent_id']);
    }

    public function testStoreChangeAfterAuthorizationCannotWriteOrClear(): void
    {
        [$id, $mask] = $this->quote();
        $storeId = (int)$this->stores->getStore()->getId();
        $scope = $this->createStub(QuoteScope::class);
        $scope->method('find')->willReturnCallback(function () use ($id, $storeId) {
            $this->resource->getConnection()->update($this->table('quote'), ['store_id' => $this->foreignStore], ['entity_id = ?' => $id]);
            return ['store_id' => $storeId];
        });
        $scope->method('matches')->willReturn(true);
        $this->assertFalse($this->guest(scope: $scope)->capture($mask, 'new@example.test'));
        $this->assertSame('original', $this->row($id)['consent_id']);
        $this->assertFalse($this->guest(scope: $scope, enabled: false)->capture($mask, ''));
        $this->assertSame('fixture@example.test', $this->row($id)['customer_email']);
    }

    public function testWrongConsentWebsiteAndPurposeDoNotChangeTheQuote(): void
    {
        [$id, $mask] = $this->quote();
        $before = $this->row($id);
        $this->assertFalse($this->guest($this->consent($this->foreignWebsite))->capture($mask, 'new@example.test'));
        $this->assertFalse($this->guest($this->consent(purpose: 'analytics'))->capture($mask, 'new@example.test'));
        $this->assertSame($before, $this->row($id));
    }

    public function testAtomicGuardRefusalIsNotAcknowledgedAsStored(): void
    {
        [$id, $mask] = $this->quote();
        $before = $this->row($id);
        $this->assertFalse($this->guest(guardValid: false)->capture($mask, 'new@example.test'));
        $scope = $this->createStub(QuoteScope::class);
        // Model a stale authorization read: the quote no longer belongs to this customer.
        $scope->method('find')->willReturn(['store_id' => (int)$this->stores->getStore()->getId()]);
        $scope->method('matches')->willReturn(true);
        [$guard, $browser, $config] = $this->dependencies();
        $customer = new CustomerConsentCapture($this->resource, $this->consent(), $guard, $browser, $config, $scope,
            $this->om->get(CartTable::class));
        $this->assertFalse($customer->execute($id, 123));
        $this->assertSame($before, $this->row($id));
    }

    public function testEmailChangesHaveTheirOwnClockAndDoNotResetTheReminder(): void
    {
        [$id, $mask] = $this->quote();
        $db = $this->resource->getConnection();
        $db->update($this->table('quote'), ['updated_at' => '2026-01-01 00:00:00'], ['entity_id = ?' => $id]);
        $this->state($id, ['reminded_at' => '2026-01-01 00:00:00']);
        $before = $this->row($id);
        $guest = $this->guest();
        $this->assertTrue($guest->capture($mask, 'captured@example.test'));
        $this->assertSame($before['captured_at'], $this->row($id)['captured_at']);
        $this->assertTrue($guest->capture($mask, 'changed@example.test'));
        $changed = $this->row($id);
        $this->assertGreaterThan($before['captured_at'], $changed['captured_at']);
        $this->assertSame($before['updated_at'], $changed['updated_at']);
        $this->assertSame($before['customer_email'], $changed['customer_email']);
        $this->assertSame($before['reminded_at'], $changed['reminded_at']);
        $this->assertTrue($guest->capture($mask, 'changed@example.test'));
        $this->assertSame($changed['captured_at'], $this->row($id)['captured_at']);
    }

    public function testGuestResolverUsesOnlyTheCapturedRecipient(): void
    {
        [$id, $mask] = $this->quote();
        $resolver = $this->om->get(ReminderEmailResolverInterface::class);
        $this->assertSame('captured@example.test', $resolver->resolve($id));
        $this->guest()->capture($mask, '');
        $this->assertNull($resolver->resolve($id));
        $this->assertSame('fixture@example.test', $this->row($id)['customer_email']);
    }

    public function testCustomerResolverUsesMagentoEmailInsteadOfOldGuestCapture(): void
    {
        [$id] = $this->quote();
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create();
        $customer->setWebsiteId((int)$this->stores->getStore()->getWebsiteId())->setStoreId((int)$this->stores->getStore()->getId())
            ->setEmail('ck-resolver-' . bin2hex(random_bytes(6)) . '@example.test')->setFirstname('Fixture')->setLastname('Recipient');
        $customer = $this->om->get(CustomerRepositoryInterface::class)->save($customer);
        $this->customers[] = (int)$customer->getId();
        $this->resource->getConnection()->update($this->table('quote'), ['customer_id' => (int)$customer->getId()], ['entity_id = ?' => $id]);
        $this->assertSame('fixture@example.test', $this->om->get(ReminderEmailResolverInterface::class)->resolve($id));
    }

    public function testRegisteredPoolHandlerClearsGuestsAndCustomersButPreservesNativeEmailsAndNewGrants(): void
    {
        [$guest] = $this->quote();
        [$registered] = $this->quote();
        [$newGrant] = $this->quote();
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create();
        $customer->setWebsiteId((int)$this->stores->getStore()->getWebsiteId())->setStoreId((int)$this->stores->getStore()->getId())
            ->setEmail('ck-withdrawal-' . bin2hex(random_bytes(6)) . '@example.test')->setFirstname('Fixture')->setLastname('Recipient');
        $customer = $this->om->get(CustomerRepositoryInterface::class)->save($customer);
        $this->customers[] = (int)$customer->getId();
        $db = $this->resource->getConnection();
        $oldConsent = bin2hex(random_bytes(16));
        foreach ([$guest, $registered] as $id) {
            $this->state($id, ['consent_id' => $oldConsent, 'reminded_at' => '2026-01-01 00:00:00']);
        }
        $db->update($this->table('quote'), ['customer_id' => (int)$customer->getId()], ['entity_id = ?' => $registered]);
        $before = [$guest => $this->row($guest), $registered => $this->row($registered)];
        $newBefore = $this->row($newGrant);
        $accountEmail = $customer->getEmail();
        $pool = $this->om->get(\CommerceKings\Consent\Model\WithdrawalPool::class);
        $handler = $pool->get(Config::PURPOSE, 'abandoned_cart');
        $this->assertInstanceOf(\CommerceKings\Consent\Api\WithdrawalHandlerInterface::class, $handler);
        $context = new \CommerceKings\Consent\Api\Data\WithdrawalContext(Config::PURPOSE, [$oldConsent],
            (int)$this->stores->getStore()->getWebsiteId(), 'original-browser', 'email');
        $handler->execute($context);
        $handler->execute($context); // At-least-once retry is harmless.
        foreach ([$guest, $registered] as $id) {
            $after = $this->row($id);
            foreach (['email', 'captured_at', 'consent_id', 'browser_hash'] as $field) {
                $this->assertNull($after[$field]);
            }
            foreach (['customer_email', 'updated_at', 'reminded_at'] as $field) {
                $this->assertSame($before[$id][$field], $after[$field]);
            }
        }
        $this->assertSame($newBefore, $this->row($newGrant));
        $this->assertSame($accountEmail, $this->om->get(CustomerRepositoryInterface::class)->getById((int)$customer->getId())->getEmail());
    }
}
