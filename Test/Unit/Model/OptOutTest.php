<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\OptOut;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use CommerceKings\Consent\Api\WithdrawalLinkInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class OptOutTest extends TestCase
{
    use DbTestTrait;

    private array $ids = [];
    private bool $missing = false;

    private function optOut(): OptOut
    {
        $signature = $this->createStub(LinkSignature::class);
        $signature->method('isValid')->willReturnCallback(fn ($purpose, $id, $token) => $token === "$purpose-$id");
        $links = $this->createStub(WithdrawalLinkInterface::class);
        $links->method('getUrl')->willReturnCallback(function ($id) {
            $this->ids[] = $id;
            if ($this->missing) {
                throw new LocalizedException(__('Missing consent'));
            }
            return "https://shop/ckconsent/optout?c=$id&t=signed";
        });
        return new OptOut($this->resource(), $signature, $links);
    }

    public function testOldSignedLinkResolvesTheCapturedConsent(): void
    {
        $optOut = $this->optOut();
        $this->fetchOneResult = 'cid-1';
        $this->assertSame('https://shop/ckconsent/optout?c=cid-1&t=signed', $optOut->getWithdrawalUrl(7, 'optout-7'));
        $this->assertSame(['cid-1'], $this->ids);
        $this->assertContains(['entity_id = ?', 7], $this->selectWhere);
    }

    public function testForgedOrRestoreSignatureCannotReadTheCart(): void
    {
        $optOut = $this->optOut();
        $this->assertNull($optOut->getWithdrawalUrl(7, 'restore-7'));
        $this->assertNull($optOut->getWithdrawalUrl(7, 'forged'));
        $this->assertSame([], $this->ids);
        $this->assertSame([], $this->selectWhere);
    }

    public function testCartWithoutConsentOrDeletedCartHasNoLink(): void
    {
        $optOut = $this->optOut();
        $this->fetchOneResult = null;
        $this->assertNull($optOut->getWithdrawalUrl(7, 'optout-7'));
        $this->fetchOneResult = false;
        $this->assertNull($optOut->getWithdrawalUrl(7, 'optout-7'));
        $this->assertSame([], $this->ids);
    }

    public function testMissingOrOtherWebsiteConsentCannotBeRedirected(): void
    {
        $optOut = $this->optOut();
        $this->fetchOneResult = 'cid-1';
        $this->missing = true;
        $this->assertNull($optOut->getWithdrawalUrl(7, 'optout-7'));
    }
}
