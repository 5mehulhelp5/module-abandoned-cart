<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\ReminderEmailResolver;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use PHPUnit\Framework\TestCase;

class ReminderEmailResolverTest extends TestCase
{
    use DbTestTrait;

    /** @dataProvider recipients */
    #[\PHPUnit\Framework\Attributes\DataProvider('recipients')]
    public function testReturnsOnlyAnExistingRecipient(mixed $value, ?string $expected): void
    {
        $this->fetchOneResult = $value;
        $this->assertSame($expected, (new ReminderEmailResolver($this->resource(), $this->carts()))->resolve(42));
        $this->assertContains(['q.entity_id = ?', 42], $this->selectWhere);
        $this->assertSame([], $this->updates);
    }

    public static function recipients(): array
    {
        return ['email' => ['fixture@example.test', 'fixture@example.test'], 'missing cart' => [false, null],
            'no email' => [null, null], 'empty email' => ['', null]];
    }
}
