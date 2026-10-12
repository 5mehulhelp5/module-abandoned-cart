<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Controller;

use CommerceKings\AbandonedCart\Controller\Optout\Index;
use CommerceKings\AbandonedCart\Model\OptOut;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;

class OptoutTest extends TestCase
{
    private OptOut $optOut;
    private ?string $path = null;
    private array $page = [];

    private function controller(string $token): Index
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($k) => ['q' => '42', 't' => $token][$k] ?? null);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($p) use ($redirect) { $this->path = $p; return $redirect; });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);
        $title = $this->createStub(Title::class);
        $title->method('set')->willReturnCallback(function ($t) { $this->page['title'] = (string)$t; });
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        $config->method('setRobots')->willReturnCallback(function ($r) { $this->page['robots'] = $r; });
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);
        $this->optOut = $this->createMock(OptOut::class);
        $this->optOut->method('isValid')->willReturnCallback(fn ($id, $t) => $id === 42 && $t === 'good');
        return new Index($request, $pageFactory, $redirectFactory, $this->optOut);
    }

    public function testValidLinkWithdrawsAndShowsConfirmation(): void
    {
        $controller = $this->controller('good');
        $this->optOut->expects($this->once())->method('withdraw')->with(42)->willReturn(true);
        $this->assertInstanceOf(Page::class, $controller->execute());
        $this->assertSame(['title' => 'Odjava od podsetnika o korpi', 'robots' => 'NOINDEX,NOFOLLOW'], $this->page);
        $this->assertNull($this->path);
    }

    public function testForgedLinkGoesHomeWithoutWithdrawing(): void
    {
        $controller = $this->controller('forged');
        $this->optOut->expects($this->never())->method('withdraw');
        $controller->execute();
        $this->assertSame('', $this->path);
    }

    public function testAlreadyOptedOutGoesHome(): void
    {
        $controller = $this->controller('good');
        $this->optOut->expects($this->once())->method('withdraw')->with(42)->willReturn(false);
        $controller->execute();
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->page);
    }
}
