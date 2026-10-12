<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Controller;

use CommerceKings\AbandonedCart\Controller\Optout\Index;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use PHPUnit\Framework\TestCase;

class OptoutTest extends TestCase
{
    public function testRetiredQuoteLinksNeverRedirectToAConsentWithdrawal(): void
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())->method('setPath')->with('')->willReturnSelf();
        $redirect->expects($this->never())->method('setUrl');
        $factory = $this->createStub(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);
        $this->assertSame($redirect, (new Index($factory))->execute());
    }
}
