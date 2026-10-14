<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\LinkSignature;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;

class LinkSignatureTest extends TestCase
{
    private function signer(mixed $key = 'secret-key'): LinkSignature
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->with('crypt/key')->willReturn($key);
        return new LinkSignature($config, new Encryptor(
            $this->createStub(Random::class),
            $config,
            $this->createStub(KeyValidator::class)
        ));
    }

    public function testSignatureIsStableAnd32Hex(): void
    {
        $signer = $this->signer();
        $this->assertSame($signer->sign('restore', 10), $signer->sign('restore', 10));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $signer->sign('restore', 10));
    }

    public function testSignatureCannotBeReusedForAnotherPurpose(): void
    {
        $signer = $this->signer();
        $this->assertNotSame($signer->sign('restore', 10), $signer->sign('other-purpose', 10));
        $this->assertFalse($signer->isValid('other-purpose', 10, $signer->sign('restore', 10)));
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

    /** @dataProvider invalidKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function testSigningRejectsMissingOrInvalidDeploymentKeys(mixed $key): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The deployment crypt key is required for cart recovery links.');
        $this->signer($key)->sign('restore', 10);
    }

    /** @dataProvider invalidKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function testInvalidDeploymentKeyCannotValidateAPredictableSignature(mixed $key): void
    {
        $predictable = substr(hash_hmac('sha256', 'restore|10', (string)$key), 0, 32);
        $this->assertFalse($this->signer($key)->isValid('restore', 10, $predictable));
    }

    public static function invalidKeys(): array
    {
        return ['missing' => [null], 'empty' => [''], 'whitespace' => [" \t\n"], 'non-string' => [42]];
    }

    public function testSigningUsesInjectedMagentoEncryptor(): void
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn('secret');
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->once())->method('hash')->with('restore|10')->willReturn(str_repeat('a', 64));
        $encryptor->expects($this->never())->method('validateHash');
        $this->assertSame(str_repeat('a', 32), (new LinkSignature($config, $encryptor))->sign('restore', 10));
    }

    public function testMultipleKeysUseCurrentNativeKeyAndRejectRawDeploymentToken(): void
    {
        $key = "old-key\nnew-key";
        $signer = $this->signer($key);
        $native = substr(hash_hmac('sha256', 'restore|10', 'new-key'), 0, 32);
        $legacy = substr(hash_hmac('sha256', 'restore|10', $key), 0, 32);
        $this->assertSame($native, $signer->sign('restore', 10));
        $this->assertNotSame($native, $legacy);
        $this->assertTrue($signer->isValid('restore', 10, $native));
        $this->assertFalse($signer->isValid('restore', 10, $legacy));
        $this->assertFalse($signer->isValid('restore', 11, $native));
        $this->assertFalse($signer->isValid('other-purpose', 10, $native));
        $this->assertFalse($this->signer('different-key')->isValid('restore', 10, $native));
        $this->assertFalse($signer->isValid('restore', 10, substr(hash_hmac('sha256', 'restore|10', 'old-key'), 0, 32)));
    }

    public function testEncodedKeyUsesNativeDecodingAndRejectsRawKeySignature(): void
    {
        if (!defined('Magento\\Framework\\Config\\ConfigOptionsListConstants::STORE_KEY_ENCODED_RANDOM_STRING_PREFIX')) {
            $this->markTestSkipped('This Magento version does not support encoded deployment keys.');
        }
        $decoded = str_repeat('k', 32);
        $key = 'base64' . base64_encode($decoded);
        $signer = $this->signer($key);
        $native = substr(hash_hmac('sha256', 'restore|10', $decoded), 0, 32);
        $legacy = substr(hash_hmac('sha256', 'restore|10', $key), 0, 32);
        $this->assertSame($native, $signer->sign('restore', 10));
        $this->assertNotSame($native, $legacy);
        $this->assertTrue($signer->isValid('restore', 10, $native));
        $this->assertFalse($signer->isValid('restore', 10, $legacy));
    }

    public function testPublicHashesPasswordHashesAndMalformedTokensCannotAuthorizeRecovery(): void
    {
        $signer = $this->signer();
        $payload = 'restore|10';
        foreach ([substr(hash('sha256', $payload), 0, 32), hash('sha256', $payload),
            hash('sha256', 'salt' . $payload) . ':salt:1', str_repeat('A', 32),
            str_repeat('0', 32), $signer->sign('restore', 10) . "\n"] as $token) {
            $this->assertFalse($signer->isValid('restore', 10, $token));
        }
    }

    public function testNativeSigningFailureRejectsAnOtherwiseValidToken(): void
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn('secret');
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willThrowException(new \RuntimeException('No key available'));
        $signer = new LinkSignature($config, $encryptor);
        $legacy = substr(hash_hmac('sha256', 'restore|10', 'secret'), 0, 32);
        $this->assertFalse($signer->isValid('restore', 10, $legacy));
        $this->expectException(\RuntimeException::class);
        $signer->sign('restore', 10);
    }
}
