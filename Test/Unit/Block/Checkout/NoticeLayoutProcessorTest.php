<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Block\Checkout;

use CommerceKings\AbandonedCart\Block\Checkout\NoticeLayoutProcessor;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\Notification\Api\OptOutNoticeInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class NoticeLayoutProcessorTest extends TestCase
{
    private const TEXT = 'If you leave your cart, we may email you one reminder about it.';

    private bool $enabled = true;
    private ?string $text = self::TEXT;
    private array $asked = [];

    public function testTheNoticeGoesUnderTheGuestEmailFieldOfBothSteps(): void
    {
        $layout = $this->processor()->process($this->checkout(['shipping-step' => 'shippingAddress', 'billing-step' => 'payment']));
        foreach (['shipping-step' => 'shippingAddress', 'billing-step' => 'payment'] as $step => $parent) {
            $this->assertSame([
                'component' => 'uiComponent',
                'template' => 'CommerceKings_AbandonedCart/notice',
                'noticeText' => self::TEXT,
            ], $this->notice($layout, $step, $parent), $step);
        }
        $this->assertSame([[Config::NOTICE_GROUP, 4]], $this->asked, 'read once, for the current store');
    }

    public function testNoTextNoNotice(): void
    {
        $this->text = null;
        $layout = $this->checkout(['shipping-step' => 'shippingAddress']);
        $this->assertSame($layout, $this->processor()->process($layout));
    }

    public function testRemindersOffNoNotice(): void
    {
        $this->enabled = false;
        $layout = $this->checkout(['shipping-step' => 'shippingAddress']);
        $this->assertSame($layout, $this->processor()->process($layout));
        $this->assertSame([], $this->asked);
    }

    /** A theme without a guest email field in a step (e.g. a custom checkout) gets nothing added there. */
    public function testAStepWithoutTheEmailFieldIsLeftAlone(): void
    {
        $layout = $this->processor()->process($this->checkout(['shipping-step' => 'shippingAddress']));
        $this->assertArrayNotHasKey('billing-step', $layout['components']['checkout']['children']['steps']['children']);
        $this->assertNotNull($this->notice($layout, 'shipping-step', 'shippingAddress'));
    }

    private function processor(): NoticeLayoutProcessor
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($storeId) => $storeId === 4 && $this->enabled);
        $notice = $this->createStub(OptOutNoticeInterface::class);
        $notice->method('getText')->willReturnCallback(function ($group, $storeId) {
            $this->asked[] = [$group, $storeId];
            return $this->text;
        });
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('4');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return new NoticeLayoutProcessor($config, $notice, $stores);
    }

    /** @param array<string, string> $steps step => parent holding the guest email field */
    private function checkout(array $steps): array
    {
        $children = [];
        foreach ($steps as $step => $parent) {
            $children[$step]['children'][$parent]['children']['customer-email'] = ['component' => 'email'];
        }
        return ['components' => ['checkout' => ['children' => ['steps' => ['children' => $children]]]]];
    }

    private function notice(array $layout, string $step, string $parent): ?array
    {
        return $layout['components']['checkout']['children']['steps']['children'][$step]['children'][$parent]
            ['children']['customer-email']['children']['before-login-form']['children']['cartreminder-notice'] ?? null;
    }
}
