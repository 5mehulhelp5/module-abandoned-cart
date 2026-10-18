<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Integration;

use CommerceKings\AbandonedCart\Model\CartRestore;
use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\RestoreAccess;
use CommerceKings\Notification\Api\SubscriberInterface;
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
        $this->restoreScenarioProduct();
        if ($this->quoteIds) {
            $this->resource->getConnection()->delete($this->resource->getTableName('ck_notification_subscription'),
                ['type = ?' => 'cart_reminder', 'reference_id IN (?)' => $this->quoteIds]);
            $this->resource->getConnection()->delete($this->resource->getTableName(\CommerceKings\Notification\Api\DeliveryStateInterface::TABLE),
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
            'subject_key' => '', 'permission_ref' => 'fixture-consent']);
        $carts = $this->om->get(\CommerceKings\AbandonedCart\Model\CartTable::class);
        $carts->ensure((int)$quote->getId());
        $this->resource->getConnection()->update($carts->name(),
            ['sent_at' => gmdate('Y-m-d H:i:s')], $carts->key((int)$quote->getId()));
        return $quote;
    }

    private function access(string $hash = self::HASH): RestoreAccess
    {
        // the fixture consent was given in browser self::HASH; this visitor's browser is $hash
        $subscriber = $this->createStub(SubscriberInterface::class);
        $subscriber->method('isCurrentVisitor')->willReturnCallback(fn ($ref) => $ref === 'fixture-consent' && $hash === self::HASH);
        return new RestoreAccess($this->om->get(LinkSignature::class), $subscriber, $this->stores,
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

    /** Restore scenarios change one product nothing else in the sandbox uses, and put it back. */
    private const SCENARIO_SKU = '24-UG06';

    private array $restoreProduct = [];

    /** Sets stock (qty, in stock) of SCENARIO_SKU for one test; tearDown puts the original back. */
    private function stock(float $qty, bool $inStock = true): void
    {
        $registry = $this->om->get(StockRegistryInterface::class);
        $item = $registry->getStockItemBySku(self::SCENARIO_SKU);
        $this->restoreProduct['stock'] ??= [(float)$item->getQty(), (bool)$item->getIsInStock()];
        $item->setQty($qty)->setIsInStock($inStock);
        $registry->updateStockItemBySku(self::SCENARIO_SKU, $item);
    }

    /** Sets a store-view attribute of SCENARIO_SKU (price, status) and reindexes its price; tearDown restores it. */
    private function attribute(string $code, mixed $value): void
    {
        $product = $this->om->get(ProductRepositoryInterface::class)->get(self::SCENARIO_SKU, false, 0, true);
        $this->restoreProduct['attributes'][$code] ??= $product->getData($code);
        $this->om->get(\Magento\Catalog\Model\ResourceModel\Product\Action::class)
            ->updateAttributes([(int)$product->getId()], [$code => $value], 0);
        $this->om->get(\Magento\Catalog\Model\Indexer\Product\Price\Processor::class)->reindexRow((int)$product->getId());
    }

    private function restoreScenarioProduct(): void
    {
        if (isset($this->restoreProduct['attributes'])) {
            $product = $this->om->get(ProductRepositoryInterface::class)->get(self::SCENARIO_SKU, false, 0, true);
            $this->om->get(\Magento\Catalog\Model\ResourceModel\Product\Action::class)
                ->updateAttributes([(int)$product->getId()], $this->restoreProduct['attributes'], 0);
            $this->om->get(\Magento\Catalog\Model\Indexer\Product\Price\Processor::class)->reindexRow((int)$product->getId());
        }
        if (isset($this->restoreProduct['stock'])) {
            [$qty, $inStock] = $this->restoreProduct['stock'];
            $registry = $this->om->get(StockRegistryInterface::class);
            $item = $registry->getStockItemBySku(self::SCENARIO_SKU)->setQty($qty)->setIsInStock($inStock);
            $registry->updateStockItemBySku(self::SCENARIO_SKU, $item);
        }
        $this->restoreProduct = [];
    }

    /** Magento's own view of a cart after reloading: item errors as the shopper sees them. */
    private function errors(Quote $quote): array
    {
        $reloaded = $this->om->get(CartRepositoryInterfaceFactory::class)->create()->get((int)$quote->getId());
        $reloaded->collectTotals();
        $errors = [];
        foreach ($reloaded->getAllVisibleItems() as $item) {
            if ($item->getHasError()) { $errors[$item->getSku()] = (string)$item->getMessage(); }
        }
        return $errors;
    }

    /** Places a guest order the way checkout does (flat rate, check/money order); returns the order id. */
    private function placeOrder(Quote $quote): int
    {
        $quote = $this->om->get(CartRepositoryInterfaceFactory::class)->create()->get((int)$quote->getId());
        $address = ['firstname' => 'Restore', 'lastname' => 'Scenario', 'street' => 'Main 1', 'city' => 'Austin',
            'country_id' => 'US', 'region_id' => 57, 'postcode' => '78701', 'telephone' => '5550100',
            'email' => 'scenario@example.test'];
        $quote->setCustomerEmail('scenario@example.test')->setCustomerIsGuest(true)->setCheckoutMethod('guest');
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->collectShippingRates()
            ->setShippingMethod('flatrate_flatrate');
        $quote->getPayment()->importData(['method' => 'checkmo']);
        $quote->collectTotals();
        $this->om->get(CartRepositoryInterface::class)->save($quote);
        return (int)$this->om->get(\Magento\Quote\Api\CartManagementInterface::class)->placeOrder((int)$quote->getId());
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

    public function testMergedQuantityAboveTheStockLimitMergesWithMagentosCartError(): void
    {
        // Magento's own merge (as when a guest signs in): quantities add up; the cart shows the stock error and
        // checkout refuses the order until the shopper fixes it - restore does not decide stock
        $product = $this->om->get(ProductRepositoryInterface::class)->get('24-MB01');
        $stock = $this->om->get(StockRegistryInterface::class)->getStockItem((int)$product->getId());
        $quantity = min((int)$stock->getQty(), (int)$stock->getMaxSaleQty());
        $this->assertGreaterThan(2, $quantity);
        $source = $this->source([['24-MB01', 2, []]]);
        $current = $this->quote([['24-MB01', $quantity, []]], 'current@example.test');
        $result = $this->service()->execute((int)$source->getId(), $current, $this->token($source), 0);
        $this->assertSame((int)$current->getId(), (int)$result->getId());
        $this->assertSame(['24-MB01' => (float)($quantity + 2)], $this->quantities($current));
        $this->assertSame(0, (int)$this->resource->getConnection()->fetchOne('SELECT is_active FROM '
            . $this->resource->getTableName('quote') . ' WHERE entity_id = ?', [(int)$source->getId()]), 'consumed once');
        $reloaded = $this->om->get(\Magento\Quote\Api\CartRepositoryInterfaceFactory::class)->create()->get((int)$current->getId());
        $reloaded->collectTotals();
        $this->assertTrue((bool)array_filter($reloaded->getAllVisibleItems(), fn ($item) => $item->getHasError()),
            'Magento flags the quantity in the cart');
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

    /**
     * Restore scenarios 1 and 2 (flash sale, last unit): a cart reserves nothing, so two shoppers can restore the last
     * unit - one adopting, one merging. Magento's checkout then sells it once: when it is sold, the other cart shows the
     * stock error and its order is refused. No oversell.
     */
    public function testTheLastUnitCanBeRestoredTwiceButIsSoldOnce(): void
    {
        $this->stock(1);
        $adopter = $this->source([[self::SCENARIO_SKU, 1, []]]);
        $merger = $this->source([[self::SCENARIO_SKU, 1, []]]);
        $own = $this->quote([['24-UG03', 1, []]], 'own@example.test');
        $empty = $this->om->get(QuoteFactory::class)->create()->setStore($this->stores->getStore());
        $adopted = $this->service()->execute((int)$adopter->getId(), $empty, $this->token($adopter), 0);
        $merged = $this->service()->execute((int)$merger->getId(), $own, $this->token($merger), 0);
        $this->assertSame([self::SCENARIO_SKU => 1.0], $this->quantities($adopted), 'restoring reserves no stock');
        $this->assertSame(['24-UG03' => 1.0, self::SCENARIO_SKU => 1.0], $this->quantities($merged));
        $this->assertSame([], $this->errors($adopted));
        $this->stock(0, false);   // the first shopper bought it
        $this->assertArrayHasKey(self::SCENARIO_SKU, $this->errors($merged), 'the cart shows the stock error');
        $orderId = 0;
        try {
            $orderId = $this->placeOrder($merged);
            $this->fail('the sold-out unit must not be sold twice');
        } catch (LocalizedException $error) {
            $this->assertMatchesRegularExpression('/quantit|stock|not available|salable/i', $error->getMessage(),
                'refused for stock, not for anything else');
        } finally {
            $this->assertSame(0, $orderId, 'no order created');
        }
    }

    /** Control for the scenario above: with stock, the same checkout places the order (then removed again). */
    public function testTheScenarioCheckoutPlacesAnOrderWhenInStock(): void
    {
        $this->stock(5);
        $source = $this->source([[self::SCENARIO_SKU, 1, []]]);
        $empty = $this->om->get(QuoteFactory::class)->create()->setStore($this->stores->getStore());
        $adopted = $this->service()->execute((int)$source->getId(), $empty, $this->token($source), 0);
        $orderId = $this->placeOrder($adopted);
        try {
            $this->assertGreaterThan(0, $orderId);
        } finally {
            $this->deleteOrder($orderId);
        }
    }

    /** Removes a test order and its stock reservations (sandbox only). */
    private function deleteOrder(int $orderId): void
    {
        $orders = $this->om->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $order = $orders->get($orderId);
        // MSI records the placed order by its increment id ("object_id" is empty at that point)
        $this->resource->getConnection()->delete($this->resource->getTableName('inventory_reservation'),
            ['metadata LIKE ?' => '%"object_increment_id":"' . $order->getIncrementId() . '"%']);
        $registry = $this->om->get(\Magento\Framework\Registry::class);
        $registry->register('isSecureArea', true, true);
        try {
            $orders->delete($order);
        } finally {
            $registry->unregister('isSecureArea');
        }
    }

    /** Scenario 3: sold out between the email and the click - both adopt and merge restore, Magento flags the item. */
    public function testASoldOutProductIsRestoredWithMagentosCartError(): void
    {
        $adopter = $this->source([[self::SCENARIO_SKU, 1, []]]);
        $merger = $this->source([[self::SCENARIO_SKU, 1, []]]);
        $own = $this->quote([['24-UG03', 1, []]], 'own@example.test');
        $this->stock(0, false);
        $empty = $this->om->get(QuoteFactory::class)->create()->setStore($this->stores->getStore());
        $adopted = $this->service()->execute((int)$adopter->getId(), $empty, $this->token($adopter), 0);
        $merged = $this->service()->execute((int)$merger->getId(), $own, $this->token($merger), 0);
        $this->assertArrayHasKey(self::SCENARIO_SKU, $this->errors($adopted));
        $this->assertSame(['24-UG03' => 1.0, self::SCENARIO_SKU => 1.0], $this->quantities($merged), 'nothing lost');
        $this->assertSame([self::SCENARIO_SKU], array_keys($this->errors($merged)), 'only the sold-out item is flagged');
    }

    /** Scenario 6: the price changed after the email - the restored cart costs today's price. */
    public function testARestoredCartCostsTodaysPrice(): void
    {
        $source = $this->source([[self::SCENARIO_SKU, 2, []]]);
        $own = $this->quote([['24-UG03', 1, []]], 'own@example.test');
        $old = (float)$this->resource->getConnection()->fetchOne('SELECT price FROM '
            . $this->resource->getTableName('quote_item') . ' WHERE quote_id = ? AND sku = ?', [(int)$source->getId(), self::SCENARIO_SKU]);
        $new = round($old + 7.5, 2);
        $this->attribute('price', $new);
        $merged = $this->service()->execute((int)$source->getId(), $own, $this->token($source), 0);
        $price = (float)$this->resource->getConnection()->fetchOne('SELECT price FROM '
            . $this->resource->getTableName('quote_item') . ' WHERE quote_id = ? AND sku = ?', [(int)$merged->getId(), self::SCENARIO_SKU]);
        $this->assertSame($new, $price, 'not the price in the email');
    }

    /** Scenario 7: a product disabled after the email - restore completes, Magento handles the item, no exception. */
    public function testADisabledProductDoesNotBreakTheRestore(): void
    {
        $source = $this->source([[self::SCENARIO_SKU, 1, []], ['24-MB05', 1, []]]);
        $own = $this->quote([['24-UG03', 1, []]], 'own@example.test');
        $this->attribute('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED);
        $merged = $this->service()->execute((int)$source->getId(), $own, $this->token($source), 0);
        $quantities = $this->quantities($merged);
        $this->assertSame(1.0, $quantities['24-UG03'] ?? null, 'the shopper\'s own product stays');
        $this->assertSame(1.0, $quantities['24-MB05'] ?? null, 'available products are merged');
        $this->assertTrue(!isset($quantities[self::SCENARIO_SKU]) || isset($this->errors($merged)[self::SCENARIO_SKU]),
            'the disabled product is dropped or flagged by Magento, never sold silently');
        $this->assertSame(0, (int)$this->resource->getConnection()->fetchOne('SELECT is_active FROM '
            . $this->resource->getTableName('quote') . ' WHERE entity_id = ?', [(int)$source->getId()]), 'consumed once');
    }

    /** Scenario 8: the shopper's current cart keeps its own coupon; the saved cart's coupon is not carried over. */
    public function testTheCurrentCartsCouponIsKept(): void
    {
        $rule = $this->om->get(\Magento\SalesRule\Model\RuleFactory::class)->create();
        $code = 'CKRESTORE' . strtoupper(bin2hex(random_bytes(3)));
        $rule->setName('CK restore scenario')->setIsActive(1)->setWebsiteIds([(int)$this->stores->getStore()->getWebsiteId()])
            ->setCustomerGroupIds([0, 1])->setCouponType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC)
            ->setCouponCode($code)->setSimpleAction('by_percent')->setDiscountAmount(10)->setStopRulesProcessing(0);
        $rule->save();
        try {
            $source = $this->source([[self::SCENARIO_SKU, 1, []]]);
            $own = $this->quote([['24-UG03', 1, []]], 'own@example.test');
            $own->setCouponCode($code)->collectTotals();
            $this->om->get(CartRepositoryInterface::class)->save($own);
            $this->assertSame($code, (string)$own->getCouponCode(), 'fixture: the coupon applies');
            $merged = $this->service()->execute((int)$source->getId(), $own, $this->token($source), 0);
            $this->assertSame($code, (string)$this->resource->getConnection()->fetchOne('SELECT coupon_code FROM '
                . $this->resource->getTableName('quote') . ' WHERE entity_id = ?', [(int)$merged->getId()]));
            $this->assertSame(['24-UG03' => 1.0, self::SCENARIO_SKU => 1.0], $this->quantities($merged));
        } finally {
            $this->om->get(\Magento\SalesRule\Api\RuleRepositoryInterface::class)->deleteById((int)$rule->getId());
        }
    }
}
