<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Integration;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\ConsentWithdrawal;
use CommerceKings\AbandonedCart\Model\CustomerConsentCapture;
use CommerceKings\AbandonedCart\Model\GuestEmailCapture;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
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
        if ($this->quotes) { $db->delete($this->table('quote'), ['entity_id IN (?)' => $this->quotes]); }
        $db->delete($this->table('store'), ['store_id = ?' => $this->foreignStore]);
        $db->delete($this->table('store_group'), ['group_id = ?' => $this->foreignGroup]);
        $db->delete($this->table('store_website'), ['website_id = ?' => $this->foreignWebsite]);
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
            'customer_email' => 'fixture@example.test', 'ck_abandoned_consent_id' => 'original',
            'ck_abandoned_browser_hash' => 'original-browser',
        ], ['entity_id = ?' => $id]);
        return [$id, (string)$mask->getMaskedId()];
    }

    private function row(int $id): array
    {
        return $this->resource->getConnection()->fetchRow($this->resource->getConnection()->select()
            ->from($this->table('quote'), ['store_id', 'customer_email', 'ck_abandoned_consent_id', 'ck_abandoned_browser_hash'])
            ->where('entity_id = ?', $id));
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
            $this->om->get(ConsentWithdrawal::class), $config, $scope ?? $this->om->get(QuoteScope::class));
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
        $guest = $this->guest();
        $this->assertTrue($guest->capture($mask, 'new@example.test'));
        $this->assertTrue($guest->capture($mask, 'new@example.test'));
        $this->assertSame('new-consent', $this->row($id)['ck_abandoned_consent_id']);
        $this->assertFalse($guest->capture($mask, ''));
        $this->assertNull($this->row($id)['customer_email']);
        $this->assertNull($this->row($id)['ck_abandoned_consent_id']);
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
        $this->assertSame('original', $this->row($id)['ck_abandoned_consent_id']);
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
        $customer = new CustomerConsentCapture($this->resource, $this->consent(), $guard, $browser, $config, $scope);
        $this->assertFalse($customer->execute($id, 123));
        $this->assertSame($before, $this->row($id));
    }
}
