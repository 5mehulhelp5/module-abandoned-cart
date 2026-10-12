<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\ConsentWithdrawal;
use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\OptOut;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

class OptOutTest extends TestCase
{
    use DbTestTrait;

    private ConsentWithdrawal $withdrawal;
    private UrlInterface $url;
    private ConsentInterface $consent;

    private function optOut(bool $withdrawMocks = false, bool $urlMock = false): OptOut
    {
        $signature = $this->createStub(LinkSignature::class);
        $signature->method('sign')->willReturnCallback(fn ($purpose, $id) => "$purpose-$id");
        $signature->method('isValid')->willReturnCallback(fn ($purpose, $id, $t) => $t === "$purpose-$id");
        $this->withdrawal = $withdrawMocks ? $this->createMock(ConsentWithdrawal::class) : $this->createStub(ConsentWithdrawal::class);
        $this->url = $urlMock ? $this->createMock(UrlInterface::class) : $this->createStub(UrlInterface::class);
        $this->consent = $withdrawMocks ? $this->createMock(ConsentInterface::class) : $this->createStub(ConsentInterface::class);
        return new OptOut($this->resource(), $signature, $this->consent, $this->withdrawal, $this->url);
    }

    public function testUrlIsSignedCartIdOnly(): void
    {
        $optOut = $this->optOut(false, true);
        $this->url->expects($this->once())->method('getUrl')
            ->with('cartreminder/optout', ['_query' => ['q' => 7, 't' => 'optout-7'], '_nosid' => true])
            ->willReturn('https://shop/cartreminder/optout/?q=7&t=optout-7');
        $this->assertSame('https://shop/cartreminder/optout/?q=7&t=optout-7', $optOut->url(7));
    }

    public function testIsValidUsesOptOutPurpose(): void
    {
        $optOut = $this->optOut();
        $this->assertTrue($optOut->isValid(7, 'optout-7'));
        $this->assertFalse($optOut->isValid(7, 'restore-7'));
    }

    public function testCartWithoutConsentIsAlreadyOptedOut(): void
    {
        $optOut = $this->optOut(true);
        $this->consent->expects($this->never())->method('withdraw');
        $this->withdrawal->expects($this->never())->method('forConsents');
        $this->fetchOneResult = null;
        $this->assertFalse($optOut->withdraw(7));
        $this->fetchOneResult = false; // cart gone
        $this->assertFalse($optOut->withdraw(7));
    }

    public function testWithdrawsTheCartsConsentNotTheEmail(): void
    {
        $optOut = $this->optOut(true);
        $this->fetchOneResult = 'cid-1';
        $this->consent->expects($this->once())->method('withdraw')->with('cid-1', 'email')->willReturn(['cid-1']);
        // the event clears the carts; cleared here too in case the consent was withdrawn earlier
        $this->withdrawal->expects($this->once())->method('forConsents')->with(['cid-1']);
        $this->assertTrue($optOut->withdraw(7));
    }
}
