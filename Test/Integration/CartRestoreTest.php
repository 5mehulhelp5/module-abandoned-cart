<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Integration;

use CommerceKings\AbandonedCart\Model\CartRestore;
use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\RestoreAccess;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/** Uses real Magento quotes in a disposable development database; deletes only its own fixtures. */
class CartRestoreTest extends TestCase
{
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private StoreManagerInterface $stores;
    private array $quoteIds = [];

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
    }

    protected function tearDown(): void
    {
        if ($this->quoteIds) {
            $this->resource->getConnection()->delete($this->resource->getTableName('ck_notification_subscription'),
                ['type = ?' => 'cart_reminder', 'reference_id IN (?)' => $this->quoteIds]);
            $this->resource->getConnection()->delete($this->resource->getTableName('quote'), ['entity_id IN (?)' => $this->quoteIds]);
        }
    }

    private function quote(array $products, string $email): Quote
    {
        $quote = $this->om->get(QuoteFactory::class)->create();
        $quote->setStore($this->stores->getStore())->setIsActive(true)->setCustomerIsGuest(true)
            ->setCustomerGroupId(0)->setCustomerEmail($email);
        foreach ($products as [$sku, $quantity, $attributes]) {
            $product = $this->om->get(ProductRepositoryInterface::class)->get($sku, false, $quote->getStoreId(), true);
            $result = $quote->addProduct($product, new DataObject(['qty' => $quantity, 'super_attribute' => $attributes]));
            if (is_string($result)) {
                throw new \RuntimeException($result);
            }
        }
        $this->om->get(CartRepositoryInterface::class)->save($quote);
        $this->quoteIds[] = (int)$quote->getId();
        return $quote;
    }

    private function source(array $products): Quote
    {
        $quote = $this->quote($products, 'source@example.test');
        $this->resource->getConnection()->update($this->resource->getTableName('quote'), [
            'coupon_code' => 'SOURCE-COUPON',
        ], ['entity_id = ?' => (int)$quote->getId()]);
        // the cart's subscription from the owner browser (Notification) and its send history: reminder sent
        $this->resource->getConnection()->insert($this->resource->getTableName('ck_notification_subscription'), [
            'type' => 'cart_reminder', 'reference_id' => (int)$quote->getId(), 'store_id' => (int)$quote->getStoreId(),
            'subject_key' => '', 'consent_id' => 'fixture-consent', 'browser_hash' => self::HASH]);
        $carts = $this->om->get(\CommerceKings\AbandonedCart\Model\CartTable::class);
        $carts->ensure((int)$quote->getId());
        $this->resource->getConnection()->update($carts->name(),
            ['reminded_at' => gmdate('Y-m-d H:i:s')], ['quote_id = ?' => (int)$quote->getId()]);
        return $quote;
    }

    private function access(string $hash = self::HASH): RestoreAccess
    {
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturn($hash);
        return new RestoreAccess($this->om->get(LinkSignature::class), $browser, $this->stores,
            $this->om->get(\CommerceKings\AbandonedCart\Model\QuoteLifetime::class), $this->om->get(\CommerceKings\AbandonedCart\Model\CartTable::class),
            $this->om->get(\CommerceKings\Notification\Api\SubscriptionRepositoryInterface::class));
    }

    private function service(?CartRepositoryInterfaceFactory $factory = null, ?RestoreAccess $access = null): CartRestore
    {
        return new CartRestore($this->resource, $factory ?? $this->om->get(CartRepositoryInterfaceFactory::class), $access ?? $this->access());
    }

    private function token(Quote $source): string
    {
        return $this->om->get(LinkSignature::class)->sign('restore', (int)$source->getId());
    }

    private function quantities(Quote $quote): array
    {
        $items = $this->resource->getConnection()->fetchPairs($this->resource->getConnection()->select()
            ->from($this->resource->getTableName('quote_item'), ['sku', 'qty'])
            ->where('quote_id = ?', (int)$quote->getId())->where('parent_item_id IS NULL'));
        $items = array_map('floatval', $items);
        ksort($items);
        return $items;
    }

    public function testRealMergeKeepsCurrentProductsAndEmailAndConsumesTheSourceOnce(): void
    {
        $source = $this->source([['24-MB01', 2, []], ['24-MB05', 1, []]]);
        $current = $this->quote([['24-MB01', 3, []], ['24-MB04', 1, []]], 'current@example.test');
        $result = $this->service()->execute((int)$source->getId(), $current, $this->token($source), 0);
        $this->assertSame((int)$current->getId(), (int)$result->getId());
        $this->assertSame(['24-MB01' => 5.0, '24-MB04' => 1.0, '24-MB05' => 1.0], $this->quantities($current));
        $row = $this->resource->getConnection()->fetchRow('SELECT is_active, customer_email, coupon_code FROM '
            . $this->resource->getTableName('quote') . ' WHERE entity_id = ?', [(int)$current->getId()]);
        $this->assertSame('current@example.test', $row['customer_email']);
        $this->assertNotSame('SOURCE-COUPON', $row['coupon_code']);
        $this->assertSame(0, (int)$this->resource->getConnection()->fetchOne('SELECT is_active FROM '
            . $this->resource->getTableName('quote') . ' WHERE entity_id = ?', [(int)$source->getId()]));
        try {
            $this->service()->execute((int)$source->getId(), $current, $this->token($source), 0);
            $this->fail('repeat restore must not add quantities twice');
        } catch (LocalizedException) {
            $this->assertSame(['24-MB01' => 5.0, '24-MB04' => 1.0, '24-MB05' => 1.0], $this->quantities($current));
        }
    }

    public function testARealSecondSaveFailureRollsBackDestinationItemsAndLeavesSourceOpen(): void
    {
        $source = $this->source([['24-MB01', 2, []]]);
        $current = $this->quote([['24-MB01', 3, []]], 'current@example.test');
        $repository = $this->om->get(CartRepositoryInterfaceFactory::class)->create();
        $proxy = $this->createStub(CartRepositoryInterface::class);
        $proxy->method('get')->willReturnCallback(fn ($id) => $repository->get($id));
        $saves = 0;
        $proxy->method('save')->willReturnCallback(function ($quote) use ($repository, &$saves) {
            if (++$saves === 2) {
                throw new \RuntimeException('Simulated second save failure');
            }
            $repository->save($quote);
        });
        $factory = $this->createStub(CartRepositoryInterfaceFactory::class);
        $factory->method('create')->willReturn($proxy);
        try {
            $this->service($factory)->execute((int)$source->getId(), $current, $this->token($source), 0);
            $this->fail('save failure expected');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated second save failure', $error->getMessage());
        }
        $this->assertSame(['24-MB01' => 3.0], $this->quantities($current));
        $this->assertSame(['24-MB01' => 2.0], $this->quantities($source));
        $this->assertSame(1, (int)$this->resource->getConnection()->fetchOne('SELECT is_active FROM '
            . $this->resource->getTableName('quote') . ' WHERE entity_id = ?', [(int)$source->getId()]));
        $this->assertSame(0, $this->resource->getConnection()->getTransactionLevel());
    }

    public function testDifferentBrowserCannotChangeEitherRealCart(): void
    {
        $source = $this->source([['24-MB01', 2, []]]);
        $current = $this->quote([['24-MB01', 3, []]], 'current@example.test');
        try {
            $this->service(access: $this->access(str_repeat('b', 64)))
                ->execute((int)$source->getId(), $current, $this->token($source), 0);
            $this->fail('different browser must be rejected');
        } catch (LocalizedException) {
            $this->assertSame(['24-MB01' => 3.0], $this->quantities($current));
            $this->assertSame(['24-MB01' => 2.0], $this->quantities($source));
        }
    }

    public function testGuestWithoutASessionCartAdoptsTheSourceWithoutCopyingItems(): void
    {
        $source = $this->source([['24-MB01', 2, []]]);
        $empty = $this->om->get(QuoteFactory::class)->create()->setStore($this->stores->getStore());
        $result = $this->service()->execute((int)$source->getId(), $empty, $this->token($source), 0);
        $this->assertSame((int)$source->getId(), (int)$result->getId());
        $this->assertNull($empty->getId());
        $this->assertSame(['24-MB01' => 2.0], $this->quantities($source));
    }

    public function testMergedQuantityAboveTheStockLimitLeavesBothCartsUnchanged(): void
    {
        $product = $this->om->get(ProductRepositoryInterface::class)->get('24-MB01');
        $stock = $this->om->get(StockRegistryInterface::class)->getStockItem((int)$product->getId());
        $quantity = min((int)$stock->getQty(), (int)$stock->getMaxSaleQty());
        $this->assertGreaterThan(2, $quantity);
        $source = $this->source([['24-MB01', 2, []]]);
        $current = $this->quote([['24-MB01', $quantity, []]], 'current@example.test');
        try {
            $this->service()->execute((int)$source->getId(), $current, $this->token($source), 0);
            $this->fail('quantity beyond available stock must not close the source');
        } catch (LocalizedException) {
            $this->assertSame(['24-MB01' => (float)$quantity], $this->quantities($current));
            $this->assertSame(['24-MB01' => 2.0], $this->quantities($source));
            $this->assertSame(1, (int)$this->resource->getConnection()->fetchOne('SELECT is_active FROM '
                . $this->resource->getTableName('quote') . ' WHERE entity_id = ?', [(int)$source->getId()]));
        }
    }

    public function testConcurrentRestoresConsumeTheSourceOnlyOnce(): void
    {
        $source = $this->source([['24-MB01', 2, []]]);
        $current = $this->quote([['24-MB01', 3, []]], 'current@example.test');
        $barrier = tempnam(sys_get_temp_dir(), 'ck-restore-barrier-');
        $workers = [];
        try {
            foreach ([1, 2] as $number) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', __DIR__ . '/restore-worker.php',
                    (string)$source->getId(), (string)$current->getId(), $barrier, (string)$number],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BP);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 10;
            while (!is_file($barrier . '.1.ready') || !is_file($barrier . '.2.ready')) {
                if (microtime(true) > $deadline) {
                    $this->fail('workers did not reach the concurrent restore barrier');
                }
                usleep(10000);
            }
            file_put_contents($barrier . '.go', 'go');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $stderr);
                $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['restored'];
            }
            $workers = [];
            sort($results);
            $this->assertSame([false, true], $results);
            $this->assertSame(['24-MB01' => 5.0], $this->quantities($current));
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) { fclose($pipe); }
                    }
                    proc_close($process);
                }
            }
            foreach (glob($barrier . '*') as $file) { unlink($file); }
        }
    }

    public function testConfigurableVariantsRetainTheirChildItemsAndDistinctChoices(): void
    {
        $product = $this->om->get(ProductRepositoryInterface::class)->get('MH01', false, $this->stores->getStore()->getId(), true);
        $type = $product->getTypeInstance();
        $children = array_values(array_filter($type->getUsedProducts($product), fn ($child) => $child->isSalable()));
        $this->assertGreaterThanOrEqual(2, count($children));
        $attributes = $type->getConfigurableAttributesAsArray($product);
        $choices = [];
        foreach (array_slice($children, 0, 2) as $child) {
            $selection = [];
            foreach ($attributes as $attribute) {
                $selection[$attribute['attribute_id']] = $child->getData($attribute['attribute_code']);
            }
            $choices[] = $selection;
        }
        $source = $this->source([['MH01', 2, $choices[0]]]);
        $current = $this->quote([['MH01', 3, $choices[0]], ['MH01', 1, $choices[1]]], 'current@example.test');
        $this->service()->execute((int)$source->getId(), $current, $this->token($source), 0);
        $parents = $this->resource->getConnection()->fetchAll('SELECT item_id, qty FROM '
            . $this->resource->getTableName('quote_item') . ' WHERE quote_id = ? AND parent_item_id IS NULL', [(int)$current->getId()]);
        $quantities = array_map(fn ($item) => (float)$item['qty'], $parents);
        sort($quantities);
        $this->assertSame([1.0, 5.0], $quantities);
        foreach ($parents as $parent) {
            $this->assertSame(1, (int)$this->resource->getConnection()->fetchOne('SELECT COUNT(*) FROM '
                . $this->resource->getTableName('quote_item') . ' WHERE quote_id = ? AND parent_item_id = ?',
                [(int)$current->getId(), (int)$parent['item_id']]));
        }
    }
}
