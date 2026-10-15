<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\RecipientHistory;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;

class RecipientHistoryTest extends TestCase
{
    use DbTestTrait;

    private function history(): RecipientHistory
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(function ($input) {
            $this->assertSame('commercekings_abandoned_cart_recipient|guest@example.test', $input);
            return str_repeat('a', 64);
        });
        $random = $this->createStub(Random::class);
        $random->method('getRandomString')->willReturn(str_repeat('b', 32));
        $config = $this->createStub(Config::class);
        $config->method('getRecipientCooldownDays')->willReturn(7);
        return new RecipientHistory($this->resource(), $encryptor, $random, $config);
    }

    public function testNormalizationUsesTheNativeKeyedHash(): void
    {
        $this->assertSame(str_repeat('a', 64), $this->history()->hash('  Guest@Example.Test  '));
    }

    public function testClaimChecksOwnershipRatherThanAffectedRowCount(): void
    {
        $this->queryRowCounts = [1, 1]; // CLIENT_FOUND_ROWS may report an unchanged row as affected.
        $history = $this->history();
        $this->fetchOneResult = str_repeat('c', 32);
        $this->assertNull($history->claim('guest@example.test'));
        $this->fetchOneResult = str_repeat('b', 32);
        $this->assertSame(str_repeat('b', 32), $history->claim('guest@example.test'));
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 32)], $this->queries[0][1]);
    }

    public function testReleaseRequiresTheClaimToken(): void
    {
        $this->history()->release('guest@example.test', 'owner-token');
        $this->assertSame(['recipient_hash = ?' => str_repeat('a', 64), 'claim_token = ?' => 'owner-token'],
            $this->deletes[0]['where']);
    }

    public function testCleanupUsesTheConfiguredRetention(): void
    {
        $this->assertSame(3, $this->history()->cleanUp());
        $this->assertSame(['reminded_at < NOW() - INTERVAL ? DAY' => 7], $this->deletes[0]['where']);
    }
}
