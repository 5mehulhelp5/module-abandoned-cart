<?php
declare(strict_types=1);

// The finder's clock, controllable from the test (it calls microtime() unqualified in its own namespace).
namespace CommerceKings\AbandonedCart\Cron {
    function microtime(bool $asFloat = false): float|string
    {
        return $GLOBALS['ckFinderClock'] ?? \microtime($asFloat);
    }
}

namespace CommerceKings\AbandonedCart\Test\Unit\Cron {
    use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
    use CommerceKings\AbandonedCart\Cron\FindAbandonedCarts;
    use CommerceKings\AbandonedCart\Model\Config;
    use CommerceKings\AbandonedCart\Model\QuoteLifetime;
    use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
    use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
    use CommerceKings\Notification\Api\CooldownInterface;
    use CommerceKings\Notification\Api\QueueInterface;
    use Magento\Framework\Lock\LockManagerInterface;
    use Magento\Store\Api\Data\StoreInterface;
    use Magento\Store\Model\StoreManagerInterface;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\LoggerInterface;

    /** The 30-second budget bounds scanning and publishing, not only whole pages (review of c77e0cb). */
    class FindAbandonedCartsDeadlineTest extends TestCase
    {
        use DbTestTrait;

        private array $published = [];
        /** the clock jumps to this when the n-th recipient is resolved */
        private array $jumps = [];
        private int $resolved = 0;
        /** @var callable|null */
        private $afterPublish = null;

        protected function tearDown(): void
        {
            unset($GLOBALS['ckFinderClock']);
        }

        private function finder(): FindAbandonedCarts
        {
            $config = $this->createStub(Config::class);
            $config->method('isEnabled')->willReturn(true);
            $config->method('getDelayMinutes')->willReturn(60);
            $config->method('getMaxPending')->willReturn(2000);
            $config->method('getMaxPerHour')->willReturn(0);
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn(1);
            $stores = $this->createStub(StoreManagerInterface::class);
            $stores->method('getStores')->willReturn([1 => $store]);
            $lifetime = $this->createStub(QuoteLifetime::class);
            $lifetime->method('validCondition')->willReturn('NOT_EXPIRED');
            $queue = $this->createStub(QueueInterface::class);
            $queue->method('publish')->willReturnCallback(function ($type, $id) {
                $this->published[] = $id;
                if ($this->afterPublish) { ($this->afterPublish)(); }
            });
            $recipients = $this->createStub(ReminderEmailResolverInterface::class);
            $recipients->method('resolve')->willReturnCallback(function () {
                $this->resolved++;
                if (isset($this->jumps[$this->resolved])) { $GLOBALS['ckFinderClock'] = $this->jumps[$this->resolved]; }
                return 'fixture@example.test';
            });
            $locks = $this->createStub(LockManagerInterface::class);
            $locks->method('lock')->willReturn(true);
            return new FindAbandonedCarts($config, $this->resource(), $this->guard(), $queue, new OncePerCart(), $lifetime,
                $stores, $this->createStub(LoggerInterface::class), $recipients, $this->createStub(CooldownInterface::class),
                $this->carts(), $locks);
        }

        public function testAScanThatUsesUpTheBudgetPublishesNothing(): void
        {
            $GLOBALS['ckFinderClock'] = 100.0;
            $this->jumps = [3 => 131.0]; // the scan of three candidates takes past the 30 s budget
            $this->fetchPairsResults = [[10 => '1010', 11 => '1011', 12 => '1012']];
            $this->assertSame(0, $this->finder()->execute());
            $this->assertSame([], $this->published);
            $this->assertCount(1, $this->updates, 'only stale recovery ran, no claim');
        }

        public function testPublishingStopsAtTheDeadlineMidPage(): void
        {
            $GLOBALS['ckFinderClock'] = 100.0;
            $this->fetchPairsResults = [[10 => '1010', 11 => '1011', 12 => '1012']];
            $this->afterPublish = function () { $GLOBALS['ckFinderClock'] = 131.0; }; // the first publish is slow
            $this->assertSame(1, $this->finder()->execute(), 'one claim, then the budget is over');
            $this->assertSame(['1010'], $this->published);
            $this->assertCount(2, $this->updates, 'stale recovery and one claim; the rest is left for the next run');
        }

        public function testWithinTheBudgetTheWholePageIsPublished(): void
        {
            $GLOBALS['ckFinderClock'] = 100.0;
            $this->fetchPairsResults = [[10 => '1010', 11 => '1011', 12 => '1012']];
            $this->assertSame(3, $this->finder()->execute());
        }
    }
}
