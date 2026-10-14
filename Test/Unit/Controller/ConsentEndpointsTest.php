<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Controller;

use CommerceKings\AbandonedCart\Controller\Consent\Capture;
use CommerceKings\AbandonedCart\Model\ConsentWithdrawal;
use CommerceKings\AbandonedCart\Model\CustomerConsentCapture;
use CommerceKings\AbandonedCart\Model\WithdrawalHandler;
use CommerceKings\Consent\Api\Data\WithdrawalContext;
use CommerceKings\Consent\Model\WithdrawalPool;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use PHPUnit\Framework\TestCase;

class ConsentEndpointsTest extends TestCase
{
    use DbTestTrait;

    private array $json = [];
    private int $status = 200;

    private function controller(CustomerSession $customer, CustomerConsentCapture $capture,
        mixed $key = 'valid', bool $keyValid = true): Capture
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($key);
        $validator = $this->createStub(Validator::class);
        $validator->method('validate')->willReturn($keyValid);
        return new Capture($this->jsonFactory(), $this->checkout(42), $customer, $capture, $request, $validator);
    }

    private function jsonFactory(): JsonFactory
    {
        $json = $this->createStub(Json::class);
        $json->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($json) { $this->status = $code; return $json; });
        $json->method('setData')->willReturnCallback(function ($d) use ($json) { $this->json = $d; return $json; });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        return $factory;
    }

    private function checkout(?int $quoteId): CheckoutSession
    {
        return $this->mockWith(CheckoutSession::class, ['getQuoteId' => $quoteId]);
    }

    private function withdrawn(string $purpose, ConsentWithdrawal $withdrawal): void
    {
        $pool = new WithdrawalPool(['cart_reminder' => ['abandoned_cart' => new WithdrawalHandler($withdrawal)]]);
        $pool->get($purpose, 'abandoned_cart')?->execute(new WithdrawalContext(
            $purpose, ['c1', 'c2'], 1, 'bh', 'banner'
        ));
    }

    public function testWithdrawalClearsEveryCartCapturedWithTheWithdrawnConsents(): void
    {
        // also the abandoned cart of an expired session - found by its consent id, not the session
        $withdrawal = $this->createMock(ConsentWithdrawal::class);
        $withdrawal->expects($this->once())->method('forConsents')->with(['c1', 'c2']);
        $this->withdrawn('cart_reminder', $withdrawal);
    }

    public function testOtherPurposesAreNotOurs(): void
    {
        $withdrawal = $this->createMock(ConsentWithdrawal::class);
        $withdrawal->expects($this->never())->method('forConsents');
        $this->withdrawn('newsletter', $withdrawal);
    }

    public function testCaptureForLoggedInCustomerUsesSessionIds(): void
    {
        $customer = $this->mockWith(CustomerSession::class, ['isLoggedIn' => true, 'getCustomerId' => 15]);
        $capture = $this->createMock(CustomerConsentCapture::class);
        $capture->expects($this->once())->method('execute')->with(42, 15)->willReturn(true);
        $this->controller($customer, $capture)->execute();
        $this->assertSame(['stored' => true], $this->json);
    }

    public function testCaptureIgnoresGuests(): void
    {
        $customer = $this->mockWith(CustomerSession::class, ['isLoggedIn' => false, 'getCustomerId' => null]);
        $capture = $this->createMock(CustomerConsentCapture::class);
        $capture->expects($this->never())->method('execute');
        $this->controller($customer, $capture)->execute();
        $this->assertSame(['stored' => false], $this->json);
    }

    /** @dataProvider invalidKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function testInvalidFormKeyCannotCaptureEvenForALoggedInCustomer(mixed $key, bool $valid): void
    {
        $customer = $this->mockWith(CustomerSession::class, ['isLoggedIn' => true, 'getCustomerId' => 15]);
        $capture = $this->createMock(CustomerConsentCapture::class);
        $capture->expects($this->never())->method('execute');
        $this->controller($customer, $capture, $key, $valid)->execute();
        $this->assertSame(403, $this->status);
        $this->assertSame(['stored' => false], $this->json);
    }

    public static function invalidKeys(): array
    {
        return ['missing' => [null, true], 'array' => [['bad'], true], 'invalid' => ['bad', false]];
    }
}
