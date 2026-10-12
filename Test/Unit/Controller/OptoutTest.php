<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Controller;

use CommerceKings\AbandonedCart\Controller\Optout\Index;
use CommerceKings\AbandonedCart\Model\OptOut;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use PHPUnit\Framework\TestCase;

class OptoutTest extends TestCase
{
    private ?string $path = null;
    private ?string $url = null;

    private function controller(?string $url): Index
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($key) => ['q' => '42', 't' => 'signed'][$key]);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use ($redirect) {
            $this->path = $path;
            return $redirect;
        });
        $redirect->method('setUrl')->willReturnCallback(function ($url) use ($redirect) {
            $this->url = $url;
            return $redirect;
        });
        $factory = $this->createStub(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);
        $optOut = $this->createMock(OptOut::class);
        $optOut->expects($this->once())->method('getWithdrawalUrl')->with(42, 'signed')->willReturn($url);
        return new Index($request, $factory, $optOut);
    }

    public function testOldLinkRedirectsToConsentOwnedConfirmation(): void
    {
        $this->controller('https://shop/ckconsent/optout?c=cid&t=signed')->execute();
        $this->assertSame('https://shop/ckconsent/optout?c=cid&t=signed', $this->url);
        $this->assertNull($this->path);
    }

    public function testInvalidOrMissingConsentGoesHome(): void
    {
        $this->controller(null)->execute();
        $this->assertSame('', $this->path);
        $this->assertNull($this->url);
    }
}
