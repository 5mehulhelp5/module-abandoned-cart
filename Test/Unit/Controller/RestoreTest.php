<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Controller;

use CommerceKings\AbandonedCart\Controller\Cart\Restore;
use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

class RestoreTest extends TestCase
{
    use DbTestTrait;

    private ?string $path = null;
    private array $messages = [];
    private array $saved = [];
    private array $replaced = [];
    private array $cookies = [];
    private ?string $sectionCookie = '{"cart":100,"customer":200}';

    /**
     * @param array $cart       saved cart: active, items, customer, ref
     * @param ?array $current   this browser's session cart (null = none): id, items
     */
    private function restore(
        array $cart = [],
        ?string $browserHash = 'bh-owner',
        ?array $current = null,
        int $loggedInCustomer = 0,
        string $token = 'good',
        bool $cartExists = true,
        bool $quoteModel = true
    ): Quote {
        $cart += ['id' => 42, 'active' => 1, 'items' => 2, 'customer' => null, 'browser' => 'bh-owner',
            'reminded' => gmdate('Y-m-d H:i:s', time() - 3600)];
        $quote = $this->mockWith(Quote::class, [
            'getId' => $cart['id'], 'getIsActive' => $cart['active'], 'getItemsCount' => $cart['items'],
            'getCustomerId' => $cart['customer'],
            'getData' => fn ($key = '') => ['ck_abandoned_browser_hash' => $cart['browser'], 'ck_abandoned_reminded_at' => $cart['reminded']][$key] ?? null,
            'setIsActive' => self::$SELF,
        ]);
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($k) => ['q' => '42', 't' => $token][$k] ?? null);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use ($redirect) { $this->path = $path; return $redirect; });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);
        $signature = $this->createStub(LinkSignature::class);
        $signature->method('isValid')->willReturnCallback(fn ($purpose, $id, $t) => $purpose === 'restore' && $id === 42 && $t === 'good');
        $browserId = $this->createStub(BrowserIdentityInterface::class);
        $browserId->method('hash')->willReturn((string)$browserHash);
        $repo = $this->createStub(CartRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(function () use ($quote, $cartExists, $quoteModel) {
            if (!$cartExists) {
                throw new NoSuchEntityException();
            }
            return $quoteModel ? $quote : $this->createStub(CartInterface::class);
        });
        $repo->method('save')->willReturnCallback(function ($q) { $this->saved[] = $q; });

        $currentQuote = null;
        if ($current) {
            $currentQuote = $this->mockWith(Quote::class, [
                'getId' => $current['id'], 'getItemsCount' => $current['items'], 'merge' => self::$SELF, 'collectTotals' => self::$SELF,
            ]);
            $currentQuote->method('merge')->willReturnCallback(function ($merged) use ($quote, $currentQuote) {
                $this->assertSame($quote, $merged);
                return $currentQuote;
            });
        }
        $checkout = $this->mockWith(CheckoutSession::class, [
            'getQuoteId' => $current['id'] ?? null,
            'getQuote' => $currentQuote,
            'replaceQuote' => function ($q) { $this->replaced[] = $q; },
        ]);
        $customer = $this->mockWith(CustomerSession::class, ['getCustomerId' => $loggedInCustomer ?: null]);
        $messages = $this->createStub(ManagerInterface::class);
        foreach (['addNoticeMessage', 'addSuccessMessage'] as $method) {
            $messages->method($method)->willReturnCallback(function ($m) use ($messages) { $this->messages[] = (string)$m; return $messages; });
        }
        $cookieManager = $this->createStub(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(fn ($name) => $name === 'section_data_ids' ? $this->sectionCookie : null);
        $cookieManager->method('setPublicCookie')->willReturnCallback(function ($name, $value) { $this->cookies[$name] = $value; });
        $metadata = $this->mockWith(PublicCookieMetadata::class, ['setPath' => self::$SELF, 'setDuration' => self::$SELF]);
        $metadataFactory = $this->createStub(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')->willReturn($metadata);

        (new Restore($request, $redirectFactory, $signature, $browserId, $repo, $checkout, $customer, $messages, $cookieManager, $metadataFactory))->execute();
        return $quote;
    }

    public function testForgedOrOldLinkGoesHome(): void
    {
        $this->restore([], 'bh-owner', null, 0, 'forged');
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->replaced);
    }

    public function testMissingCartGoesHome(): void
    {
        $this->restore([], 'bh-owner', null, 0, 'good', false);
        $this->assertSame('', $this->path);
    }

    public function testUnsupportedCartRepresentationGoesHomeWithoutRestoring(): void
    {
        $this->restore(quoteModel: false);
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->messages);
        $this->assertSame([], $this->replaced);
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->cookies);
    }

    /** @dataProvider otherBrowsers */
    #[\PHPUnit\Framework\Attributes\DataProvider('otherBrowsers')]
    public function testOtherBrowserGoesHomeAndGetsNothing(?string $browserHash, array $cart): void
    {
        $this->restore($cart, $browserHash);
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->messages, 'no hint whether the cart was ordered');
        $this->assertSame([], $this->replaced);
        $this->assertSame([], $this->cookies);
    }

    public static function otherBrowsers(): array
    {
        return [
            'no browser id cookie' => [null, []],
            'another browser' => ['bh-other', []],
            'stranger, cart ordered' => ['bh-other', ['active' => 0]],
            'cart has no consent (opted out)' => ['bh-owner', ['browser' => null]],
            'customer cart, other browser' => ['bh-other', ['customer' => 15]],
        ];
    }

    public function testExpiredLinkFromAnotherBrowserStillGoesHome(): void
    {
        $this->restore(['reminded' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)], 'bh-other');
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->messages);
    }

    public function testLinkStillWorksOnDaySix(): void
    {
        $this->restore(['reminded' => gmdate('Y-m-d H:i:s', time() - 6 * 86400)]);
        $this->assertSame('checkout/cart', $this->path);
        $this->assertSame([], $this->messages);
    }

    /** @dataProvider unavailable */
    #[\PHPUnit\Framework\Attributes\DataProvider('unavailable')]
    public function testOwnerSeesNoLongerAvailable(array $cart): void
    {
        $this->restore($cart);
        $this->assertSame('checkout/cart', $this->path);
        $this->assertSame(['This cart is no longer available.'], $this->messages);
        $this->assertSame([], $this->replaced);
    }

    public static function unavailable(): array
    {
        return [
            'ordered' => [['active' => 0]],
            'emptied' => [['items' => 0]],
            'link older than 7 days' => [['reminded' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)]],
            'never reminded' => [['reminded' => null]],
        ];
    }

    public function testCustomerCartLoggedInGoesToCart(): void
    {
        $this->restore(['customer' => 15], 'bh-owner', null, 15);
        $this->assertSame('checkout/cart', $this->path);
        $this->assertSame([], $this->replaced);
    }

    /** @dataProvider notThatCustomer */
    #[\PHPUnit\Framework\Attributes\DataProvider('notThatCustomer')]
    public function testCustomerCartAsksToLogIn(int $loggedIn): void
    {
        $this->restore(['customer' => 15], 'bh-owner', null, $loggedIn);
        $this->assertSame('customer/account/login', $this->path);
        $this->assertSame(['Sign in to continue shopping – your cart is waiting for you.'], $this->messages);
        $this->assertSame([], $this->replaced);
    }

    public static function notThatCustomer(): array
    {
        return ['logged out' => [0], 'another customer' => [99]];
    }

    public function testGuestBrowserAlreadyHasThisCart(): void
    {
        $this->restore([], 'bh-owner', ['id' => 42, 'items' => 2]);
        $this->assertSame('checkout/cart', $this->path);
        $this->assertSame([], $this->replaced);
        $this->assertSame([], $this->cookies);
    }

    /** @dataProvider lostCart */
    #[\PHPUnit\Framework\Attributes\DataProvider('lostCart')]
    public function testGuestLostCartIsRestoredAndMiniCartRefreshed(?array $current): void
    {
        $quote = $this->restore([], 'bh-owner', $current);
        $this->assertSame('checkout/cart', $this->path);
        $this->assertSame([$quote], $this->replaced);
        $ids = json_decode($this->cookies['section_data_ids'], true);
        $this->assertGreaterThan(100, $ids['cart']);
        $this->assertSame(200, $ids['customer'], 'other sections untouched');
    }

    public static function lostCart(): array
    {
        return ['session expired, no cart' => [null], 'empty cart' => [['id' => 7, 'items' => 0]]];
    }

    public function testGuestWithOtherItemsIsMerged(): void
    {
        $this->sectionCookie = null;
        $quote = $this->restore([], 'bh-owner', ['id' => 7, 'items' => 1]);
        $this->assertSame('checkout/cart', $this->path);
        $this->assertCount(2, $this->saved, 'merged cart saved, saved cart closed');
        $this->assertSame($quote, $this->saved[1]);
        $this->assertSame(['We have added the products from your saved cart to your cart.'], $this->messages);
        $this->assertArrayHasKey('cart', json_decode($this->cookies['section_data_ids'], true));
    }
}
