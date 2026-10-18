<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\RestoreLink;
use CommerceKings\Notification\Api\SubscriberInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

class RestoreLinkTest extends TestCase
{
    private array $scopes = [];

    private function link(bool $identifies): RestoreLink
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('setScope')->willReturnCallback(function ($scope) use ($url) {
            $this->scopes[] = $scope;
            return $url;
        });
        $url->method('getUrl')->willReturnCallback(fn ($route, $params = []) => 'https://shop/' . $route
            . (isset($params['_query']) ? '?' . http_build_query($params['_query']) : '')
            . (($params['_nosid'] ?? false) ? '' : '#sid'));
        $signature = $this->createStub(LinkSignature::class);
        $signature->method('sign')->willReturnCallback(fn ($purpose, $id) => "$purpose-$id");
        $subscriber = $this->createStub(SubscriberInterface::class);
        $subscriber->method('identifiesVisitor')->willReturn($identifies);
        return new RestoreLink($url, $signature, $subscriber);
    }

    private function quote(): Quote
    {
        $quote = $this->createStub(Quote::class);
        $quote->method('getId')->willReturn(42);
        $quote->method('getStoreId')->willReturn(3);
        return $quote;
    }

    public function testTheEmailReminderGetsTheSignedLinkWhenItsSubscriberIsKnown(): void
    {
        $this->assertSame('https://shop/cartreminder/cart/restore?q=42&t=restore-42',
            $this->link(true)->getUrl($this->quote(), 'cart_reminder'));
        $this->assertSame([3], $this->scopes, 'in the cart\'s store');
        $this->assertSame('https://shop/checkout/cart', $this->link(false)->getUrl($this->quote(), 'cart_reminder'));
    }

    /** Another channel (e.g. a text opened on a phone) cannot prove the visitor: the cart page, never a signed link. */
    public function testAnotherTypeGetsTheCartPage(): void
    {
        $this->assertSame('https://shop/checkout/cart', $this->link(true)->getUrl($this->quote(), 'cart_reminder_sms'));
    }
}
