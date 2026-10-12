<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Cron;

use CommerceKings\AbandonedCart\Cron\Cleanup;
use CommerceKings\AbandonedCart\Model\RecipientHistory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupTest extends TestCase
{
    /** @dataProvider counts */
    #[\PHPUnit\Framework\Attributes\DataProvider('counts')]
    public function testDeletesOldRecipients(int $deleted, int $logs): void
    {
        $history = $this->createMock(RecipientHistory::class);
        $history->expects($this->once())->method('cleanUp')->willReturn($deleted);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly($logs))->method('info');
        $this->assertSame($deleted, (new Cleanup($history, $logger))->execute());
    }

    public static function counts(): array
    {
        return ['recipients deleted' => [4, 1], 'nothing → silent' => [0, 0]];
    }
}
