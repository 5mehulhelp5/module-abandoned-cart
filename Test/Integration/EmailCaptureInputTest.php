<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Integration;

use CommerceKings\AbandonedCart\Api\GuestEmailCaptureInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Exception\InputException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Webapi\ServiceInputProcessor;
use PHPUnit\Framework\TestCase;

/** Real REST conversion and API interception; invalid input fails before any quote access. */
class EmailCaptureInputTest extends TestCase
{
    private ObjectManagerInterface $om;

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(State::class)->setAreaCode('webapi_rest');
    }

    private function process(array $data): array
    {
        return $this->om->get(ServiceInputProcessor::class)->process(
            GuestEmailCaptureInterface::class, 'capture', ['cartId' => 'not-a-real-mask'] + $data
        );
    }

    /** @dataProvider validInputs */
    #[\PHPUnit\Framework\Attributes\DataProvider('validInputs')]
    public function testFlatStringInputIsPreserved(string $email): void
    {
        $this->assertSame(['not-a-real-mask', $email], $this->process(['email' => $email]));
    }

    public static function validInputs(): array
    {
        return ['email' => ['fixture@example.test'], 'explicit clear' => ['']];
    }

    /** @dataProvider invalidInputs */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function testRawInvalidInputReachesCaptureWithoutCoercionAndIsRejected(mixed $email): void
    {
        [$cartId, $processed] = $this->process(['email' => $email]);
        $this->assertSame($email, $processed);
        $this->expectException(InputException::class);
        $this->om->get(GuestEmailCaptureInterface::class)->capture($cartId, $processed);
    }

    public static function invalidInputs(): array
    {
        return ['format' => ['invalid'], 'length' => [str_repeat('a', 250) . '@example.test'],
            'total length' => [str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 59) . '.test'],
            'number' => [123], 'false is not clear' => [false], 'true' => [true],
            'array' => [['fixture@example.test']], 'object' => [['email' => 'fixture@example.test']],
            'IP literal' => ['fixture@[127.0.0.1]']];
    }

    /** @dataProvider missingInputs */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingInputs')]
    public function testMissingOrNullEmailIsRejectedByNativeInputProcessor(array $input): void
    {
        $this->expectException(InputException::class);
        $this->process($input);
    }

    public static function missingInputs(): array
    {
        return ['missing' => [[]], 'null' => [['email' => null]]];
    }

    public function testInternalApiCallAlsoRunsTheGlobalValidationPlugin(): void
    {
        $this->expectException(InputException::class);
        $this->om->get(GuestEmailCaptureInterface::class)->capture('not-a-real-mask', false);
    }
}
