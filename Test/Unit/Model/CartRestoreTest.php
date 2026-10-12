<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\CartRestore;
use CommerceKings\AbandonedCart\Model\RestoreAccess;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Model\Product;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\TestCase;

class CartRestoreTest extends TestCase
{
    use DbTestTrait;

    private bool $sourceActive = true;
    private bool $owner = true;
    private bool $available = true;
    private array $destination = ['id' => 7, 'customer' => 0, 'store' => 1, 'active' => true];
    private int $sourceCustomer = 0;
    private string $coupon = 'CURRENT';
    private array $merged = [];
    private array $saved = [];
    private bool $failSecondSave = false;
    private array $flags = [];
    private array $sourceItems = [];
    private Quote $source;
    private Quote $current;

    private function service(): CartRestore
    {
        $this->source = $this->mockWith(Quote::class, [
            'getId' => 42, 'getStoreId' => 1, 'getCustomerId' => fn () => $this->sourceCustomer,
            'getAllItems' => fn () => $this->sourceItems,
            'setIsActive' => function ($active) { $this->sourceActive = $active; return $this->source; },
        ]);
        $address = $this->mockWith(Address::class, [
            'setCollectShippingRates' => function ($flag) { $this->flags['shipping'] = $flag; },
        ]);
        $this->current = $this->mockWith(Quote::class, [
            'getId' => fn () => $this->destination['id'], 'getStoreId' => fn () => $this->destination['store'],
            'getCustomerId' => fn () => $this->destination['customer'], 'getIsActive' => fn () => $this->destination['active'],
            'getCouponCode' => fn () => $this->coupon,
            'getAllItems' => [], 'setIsActive' => self::$SELF,
            'setCouponCode' => function ($coupon) { $this->coupon = $coupon; return $this->current; },
            'setTotalsCollectedFlag' => function ($flag) { $this->flags['totals'] = $flag; return $this->current; },
            'getShippingAddress' => $address,
            'merge' => function ($source) { $this->merged[] = $source; $this->coupon = 'SOURCE'; return $this->current; },
        ]);
        $repository = $this->createStub(CartRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(fn ($id) => $id === 42 ? $this->source : $this->current);
        $repository->method('save')->willReturnCallback(function ($quote) {
            $this->saved[] = $quote;
            if ($this->failSecondSave && count($this->saved) === 2) {
                throw new \RuntimeException('save failed');
            }
        });
        $factory = $this->createStub(CartRepositoryInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($repository) {
            $this->transactionEvents[] = 'repository';
            return $repository;
        });
        $access = $this->createStub(RestoreAccess::class);
        $access->method('isOwner')->willReturnCallback(fn () => $this->owner);
        $access->method('isAvailable')->willReturnCallback(fn () => $this->available && $this->sourceActive);
        return new CartRestore($this->resource(), $factory, $access);
    }

    public function testLocksBeforeFreshLoadsAndCommitsBothCartsWithCurrentCoupon(): void
    {
        $service = $this->service();
        $this->assertSame($this->current, $service->execute(42, $this->current, 'token', 0));
        $this->assertSame(['beginTransaction', 'lock', 'repository', 'commit'], $this->transactionEvents);
        $this->assertContains(['entity_id IN (?)', [7, 42]], $this->selectWhere);
        $this->assertSame([true], $this->rowLocks);
        $this->assertSame([$this->source], $this->merged);
        $this->assertSame([$this->current, $this->source], $this->saved);
        $this->assertFalse($this->sourceActive);
        $this->assertSame('CURRENT', $this->coupon);
        $this->assertSame(['totals' => false, 'shipping' => true], $this->flags);
    }

    public function testSourceCannotBeMergedTwice(): void
    {
        $service = $this->service();
        $service->execute(42, $this->current, 'token', 0);
        try {
            $service->execute(42, $this->current, 'token', 0);
            $this->fail('consumed source must be rejected');
        } catch (LocalizedException) {
            $this->assertCount(1, $this->merged);
            $this->assertSame('rollBack', end($this->transactionEvents));
        }
    }

    public function testSecondSaveFailureRollsBackInsteadOfCommittingHalfTheMerge(): void
    {
        $service = $this->service();
        $this->failSecondSave = true;
        try {
            $service->execute(42, $this->current, 'token', 0);
            $this->fail('save error expected');
        } catch (\RuntimeException $error) {
            $this->assertSame('save failed', $error->getMessage());
            $this->assertSame(['beginTransaction', 'lock', 'repository', 'rollBack'], $this->transactionEvents);
        }
    }

    /** @dataProvider forbiddenCarts */
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenCarts')]
    public function testInvalidSourceOrDestinationMakesNoChanges(array $changes): void
    {
        $service = $this->service();
        foreach ($changes as $property => $value) {
            $this->$property = $value;
        }
        try {
            $service->execute(42, $this->current, 'token', 0);
            $this->fail('invalid restore must be rejected');
        } catch (LocalizedException) {
            $this->assertSame([], $this->saved);
            $this->assertSame([], $this->merged);
            $this->assertSame('rollBack', end($this->transactionEvents));
        }
    }

    public static function forbiddenCarts(): array
    {
        return [
            'other browser / bad signature' => [['owner' => false]],
            'withdrawn / expired source' => [['available' => false]],
            'customer source' => [['sourceCustomer' => 15]],
            'ordered destination' => [['destination' => ['id' => 7, 'customer' => 0, 'store' => 1, 'active' => false]]],
            'different customer' => [['destination' => ['id' => 7, 'customer' => 15, 'store' => 1, 'active' => true]]],
            'different store' => [['destination' => ['id' => 7, 'customer' => 0, 'store' => 2, 'active' => true]]],
        ];
    }

    public function testGuestWithoutAnExistingCartAdoptsTheSourceWithoutCopyingItems(): void
    {
        $service = $this->service();
        $this->destination['id'] = 0;
        $this->assertSame($this->source, $service->execute(42, $this->current, 'token', 0));
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->merged);
        $this->assertSame('commit', end($this->transactionEvents));
    }

    public function testUnavailableProductsAreRejectedBeforeEitherCartIsChanged(): void
    {
        $service = $this->service();
        $product = $this->createStub(Product::class);
        $product->method('isSalable')->willReturn(false);
        $this->sourceItems = [$this->mockWith(Item::class, [
            'getProduct' => $product, 'getHasError' => false, 'isDeleted' => false,
        ])];
        try {
            $service->execute(42, $this->current, 'token', 0);
            $this->fail('unavailable product must not be discarded by a successful merge');
        } catch (LocalizedException) {
            $this->assertSame([], $this->saved);
            $this->assertSame([], $this->merged);
            $this->assertTrue($this->sourceActive);
            $this->assertSame('rollBack', end($this->transactionEvents));
        }
    }

    public function testLoggedInCustomerKeepsTheirDestinationEvenWhenItIsEmpty(): void
    {
        $service = $this->service();
        $this->destination['customer'] = 15;
        $this->assertSame($this->current, $service->execute(42, $this->current, 'token', 15));
        $this->assertSame([$this->current, $this->source], $this->saved);
    }
}
