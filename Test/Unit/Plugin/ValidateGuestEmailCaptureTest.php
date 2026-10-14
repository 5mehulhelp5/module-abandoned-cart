<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Plugin;

use CommerceKings\AbandonedCart\Api\GuestEmailCaptureInterface;
use CommerceKings\AbandonedCart\Plugin\ValidateGuestEmailCapture;
use Laminas\Filter\StringTrim;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\Validator\StringLength;
use PHPUnit\Framework\TestCase;

class ValidateGuestEmailCaptureTest extends TestCase
{
    private function plugin(): ValidateGuestEmailCapture
    {
        $filters = $this->createStub(FilterManager::class);
        $filters->method('get')->willReturn(new StringTrim());
        return new ValidateGuestEmailCapture(new EmailAddress(), new StringLength(['max' => 255]), $filters);
    }

    /** @dataProvider validEmails */
    #[\PHPUnit\Framework\Attributes\DataProvider('validEmails')]
    public function testNormalizesValidInputAndPreservesArgumentOrder(string $email, string $expected): void
    {
        $this->assertSame(['masked-cart', $expected], $this->plugin()->beforeCapture(
            $this->createStub(GuestEmailCaptureInterface::class), 'masked-cart', $email
        ));
    }

    public static function validEmails(): array
    {
        $limit = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 57) . '.test';
        return ['email' => ['fixture@example.test', 'fixture@example.test'],
            'trimmed email' => ['  fixture@example.test  ', 'fixture@example.test'],
            'clear' => ['', ''], 'whitespace clear' => [" \t\n", ''], 'storage limit' => [$limit, $limit]];
    }

    /** @dataProvider invalidEmails */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEmails')]
    public function testRejectsInvalidInputBeforeTheServiceCanRun(mixed $email): void
    {
        $this->expectException(InputException::class);
        $this->plugin()->beforeCapture($this->createStub(GuestEmailCaptureInterface::class), 'masked-cart', $email);
    }

    public static function invalidEmails(): array
    {
        return [['not-an-email'], ['a@'], [str_repeat('a', 250) . '@test.com'],
            [null], [false], [true], [123], [['a@test.com']], ['a@[127.0.0.1]'],
            [str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 59) . '.test']];
    }

    public function testAggregatesFailuresWithoutEchoingTheEmail(): void
    {
        $email = str_repeat('a', 250) . '@example.test';
        try {
            $this->plugin()->beforeCapture($this->createStub(GuestEmailCaptureInterface::class), 'masked-cart', $email);
            $this->fail('Invalid input was accepted.');
        } catch (InputException $exception) {
            $this->assertTrue($exception->wasErrorAdded());
            $this->assertCount(2, $exception->getErrors());
            foreach ($exception->getErrors() as $error) {
                $this->assertStringNotContainsString($email, $error->getMessage());
            }
        }
    }
}
