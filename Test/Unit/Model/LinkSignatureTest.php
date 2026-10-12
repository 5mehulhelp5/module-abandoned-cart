<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\LinkSignature;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class LinkSignatureTest extends TestCase
{
    private function signer(string $key = 'secret-key'): LinkSignature
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->with('crypt/key')->willReturn($key);
        return new LinkSignature($config);
    }

    public function testSignatureIsStableAnd32Hex(): void
    {
        $signer = $this->signer();
        $this->assertSame($signer->sign('restore', 10), $signer->sign('restore', 10));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $signer->sign('restore', 10));
    }

    public function testRestoreAndOptOutSignaturesDiffer(): void
    {
        $signer = $this->signer();
        $this->assertNotSame($signer->sign('restore', 10), $signer->sign('optout', 10));
        $this->assertFalse($signer->isValid('optout', 10, $signer->sign('restore', 10)));
    }

    public function testOtherCartOrOtherKeyDoesNotValidate(): void
    {
        $signer = $this->signer();
        $this->assertFalse($signer->isValid('restore', 11, $signer->sign('restore', 10)));
        $this->assertFalse($this->signer('other-key')->isValid('restore', 10, $signer->sign('restore', 10)));
    }

    public function testValidAndForged(): void
    {
        $signer = $this->signer();
        $this->assertTrue($signer->isValid('restore', 10, $signer->sign('restore', 10)));
        $this->assertFalse($signer->isValid('restore', 10, 'deadbeef'));
        $this->assertFalse($signer->isValid('restore', 10, ''));
    }

    public function testCartIdZeroNeverValid(): void
    {
        $signer = $this->signer();
        $this->assertFalse($signer->isValid('restore', 0, $signer->sign('restore', 0)));
    }

    public function testEmailIsNotPartOfTheSignature(): void
    {
        // links in a sent email must survive an email change on the cart
        $method = new \ReflectionMethod(LinkSignature::class, 'sign');
        $this->assertSame(['purpose', 'quoteId'], array_map(fn ($p) => $p->getName(), $method->getParameters()));
    }
}
