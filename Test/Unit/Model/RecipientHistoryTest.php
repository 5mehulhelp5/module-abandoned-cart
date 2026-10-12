<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\RecipientHistory;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class RecipientHistoryTest extends TestCase
{
    use DbTestTrait;

    private function history(string $cryptKey = 'store-key'): RecipientHistory
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(fn ($path) => $path === 'crypt/key' ? $cryptKey : null);
        return new RecipientHistory($this->resource(), $config);
    }

    private static function expected(string $email, string $cryptKey = 'store-key'): string
    {
        return hash_hmac('sha256', $email, hash_hmac('sha256', 'commercekings_abandoned_cart_recipient', $cryptKey, true));
    }

    public function testHashIsKeyedAndIgnoresCaseAndSpaces(): void
    {
        $hash = $this->history()->hash(' A@Test.COM ');
        $this->assertSame(self::expected('a@test.com'), $hash);
        // a plain hash could be matched against any list of addresses; this one needs the store's key
        $this->assertNotSame(hash('sha256', 'a@test.com'), $hash);
        $this->assertNotSame($hash, $this->history('other-key')->hash('a@test.com'));
    }

    public function testNoCryptKeyIsAnError(): void
    {
        $this->expectException(\LogicException::class);
        $this->history('')->hash('a@test.com');
    }

    public function testRecentLooksUpAllAddressesInOneQuery(): void
    {
        $this->fetchColResult = [self::expected('b@test.com')];
        $this->assertSame(['B@test.com'], $this->history()->recent(['a@test.com', 'B@test.com']));
        $where = array_column($this->selectWhere, 1, 0);
        $this->assertSame([self::expected('a@test.com'), self::expected('b@test.com')], $where['recipient_hash IN (?)']);
        $this->assertSame(7, $where['reminded_at >= NOW() - INTERVAL ? DAY']);
        $this->assertSame([], $this->history()->recent([]));
    }

    public function testIsRecentLooksUpTheHashWithinTheCooldown(): void
    {
        $history = $this->history();
        $this->fetchOneResult = 'x';
        $this->assertTrue($history->isRecent('A@test.com'));
        $where = array_column($this->selectWhere, 1, 0);
        $this->assertSame(self::expected('a@test.com'), $where['recipient_hash = ?']);
        $this->assertSame(7, $where['reminded_at >= NOW() - INTERVAL ? DAY']);
        $this->fetchOneResult = false;
        $this->assertFalse($history->isRecent('a@test.com'));
    }

    /** @dataProvider claims */
    public function testClaimIsAtomicAndOnlyRefreshesExpiredRows(int $affectedRows, bool $mayAnswer): void
    {
        $history = $this->history();
        $this->queryRowCounts = [$affectedRows];
        $this->assertSame($mayAnswer, $history->claim('a@test.com'));
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('INSERT INTO ck_abandoned_cart_recipient', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE reminded_at = IF(reminded_at < NOW() - INTERVAL 7 DAY, NOW(), reminded_at)', $sql);
        $this->assertSame([self::expected('a@test.com')], $bind);
    }

    public static function claims(): array
    {
        return [
            'new address (inserted)' => [1, true],
            'expired row refreshed' => [2, true],
            'still in cooldown (unchanged)' => [0, false],
        ];
    }

    public function testReleaseAndCleanUp(): void
    {
        $history = $this->history();
        $history->release('a@test.com');
        $this->assertSame(3, $history->cleanUp());
        $this->assertSame([
            ['table' => 'ck_abandoned_cart_recipient', 'where' => ['recipient_hash = ?' => self::expected('a@test.com')]],
            ['table' => 'ck_abandoned_cart_recipient', 'where' => ['reminded_at < NOW() - INTERVAL ? DAY' => 7]],
        ], $this->deletes);
    }
}
