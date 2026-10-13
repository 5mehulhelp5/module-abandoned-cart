<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Controller;

use CommerceKings\AbandonedCart\Controller\Cart\Restore;
use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\CartRestore;
use CommerceKings\AbandonedCart\Model\RestoreAccess;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class RestoreTest extends TestCase
{
    use DbTestTrait;

    private ?string $path = null;
    private int $lookups = 0;
    private array $messages = [];
    private array $saved = [];
    private array $replaced = [];
    private array $cookies = [];
    private array $restores = [];
    private array $errors = [];
    private mixed $result = null;
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
        mixed $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        bool $cartExists = true,
        bool $quoteModel = true,
        bool $isPost = true,
        bool $formKeyValid = true,
        ?\Throwable $restoreError = null,
        mixed $quoteId = '42',
        mixed $formKey = 'valid-key'
    ): Quote {
        $cart += ['id' => 42, 'store' => 1, 'active' => 1, 'items' => 2, 'customer' => null, 'browser' => 'bh-owner',
            'reminded' => gmdate('Y-m-d H:i:s', time() - 3600), 'updated' => gmdate('Y-m-d H:i:s', time() - 3600)];
        $quote = $this->mockWith(Quote::class, [
            'getId' => $cart['id'], 'getStoreId' => $cart['store'], 'getIsActive' => $cart['active'], 'getItemsCount' => $cart['items'],
            'getCustomerId' => $cart['customer'], 'getUpdatedAt' => $cart['updated'],
            'getData' => fn ($key = '') => ['ck_abandoned_browser_hash' => $cart['browser'], 'ck_abandoned_reminded_at' => $cart['reminded']][$key] ?? null,
            'setIsActive' => self::$SELF,
        ]);
        $request = $this->createStub(Http::class);
        $request->method('isPost')->willReturn($isPost);
        $request->method('getParam')->willReturnCallback(fn ($k) => ['q' => $quoteId, 't' => $token, 'form_key' => $formKey][$k] ?? null);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use ($redirect) { $this->path = $path; return $redirect; });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);
        $signature = $this->createStub(LinkSignature::class);
        $signature->method('isValid')->willReturnCallback(fn ($purpose, $id, $t) => $purpose === 'restore' && $id === 42 && $t === 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $browserId = $this->createStub(BrowserIdentityInterface::class);
        $browserId->method('hash')->willReturn((string)$browserHash);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $lifetime = $this->createStub(\CommerceKings\AbandonedCart\Model\QuoteLifetime::class);
        $lifetime->method('isExpired')->willReturnCallback(fn ($updated) => $updated === 'expired');
        $access = new RestoreAccess($signature, $browserId, $stores, $lifetime);
        $repo = $this->createStub(CartRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(function () use ($quote, $cartExists, $quoteModel) {
            $this->lookups++;
            if (!$cartExists) {
                throw new NoSuchEntityException();
            }
            return $quoteModel ? $quote : $this->createStub(CartInterface::class);
        });
        $repo->method('save')->willReturnCallback(function ($q) { $this->saved[] = $q; });

        $currentQuote = $this->mockWith(Quote::class, ['getId' => null, 'getItemsCount' => 0]);
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
        foreach (['addNoticeMessage', 'addSuccessMessage', 'addErrorMessage'] as $method) {
            $messages->method($method)->willReturnCallback(function ($m) use ($messages) { $this->messages[] = (string)$m; return $messages; });
        }
        $cookieManager = $this->createStub(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(fn ($name) => $name === 'section_data_ids' ? $this->sectionCookie : null);
        $cookieManager->method('setPublicCookie')->willReturnCallback(function ($name, $value) { $this->cookies[$name] = $value; });
        $metadata = $this->mockWith(PublicCookieMetadata::class, ['setPath' => self::$SELF, 'setDuration' => self::$SELF]);
        $metadataFactory = $this->createStub(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')->willReturn($metadata);

        $title = $this->createStub(Title::class);
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $pages = $this->createStub(PageFactory::class);
        $pages->method('create')->willReturn($page);
        $validator = $this->createStub(Validator::class);
        $validator->method('validate')->willReturn($formKeyValid);
        $restore = $this->createStub(CartRestore::class);
        $restore->method('execute')->willReturnCallback(function ($id, $destination, $signature, $customerId) use ($quote, $current, $restoreError) {
            $this->restores[] = [$id, (int)$destination->getId(), $signature, $customerId];
            if ($restoreError) {
                throw $restoreError;
            }
            return $current ? $destination : $quote;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) { $this->errors[] = $message; });

        $this->result = (new Restore($request, $redirectFactory, $access, $repo, $checkout, $customer, $messages,
            $cookieManager, $metadataFactory, $pages, $validator, $restore, $logger))->execute();
        return $quote;
    }

    public function testForgedOrOldLinkGoesHome(): void
    {
        $this->restore([], 'bh-owner', null, 0, 'forged');
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->replaced);
    }

    /** @dataProvider forgedRequests */
    #[\PHPUnit\Framework\Attributes\DataProvider('forgedRequests')]
    public function testWellFormedForgedLinksAreRejectedBeforeQuoteLookup(string $quoteId, string $token, bool $isPost): void
    {
        $this->restore(quoteId: $quoteId, token: $token, isPost: $isPost);
        $this->assertSame('', $this->path);
        $this->assertSame(0, $this->lookups);
        $this->assertSame([], $this->restores);
        $this->assertSame([], $this->replaced);
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->messages);
        $this->assertSame([], $this->cookies);
    }

    public static function forgedRequests(): array
    {
        $valid = str_repeat('a', 32);
        $forged = str_repeat('b', 32);
        return [
            'forged GET signature' => ['42', $forged, false],
            'forged POST signature' => ['42', $forged, true],
            'changed GET quote id' => ['43', $valid, false],
            'changed POST quote id' => ['43', $valid, true],
        ];
    }

    public function testMissingCartGoesHome(): void
    {
        $this->restore([], 'bh-owner', null, 0, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', false);
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

    public function testGetOnlyShowsConfirmationAndDoesNotTouchTheCurrentCart(): void
    {
        $this->restore(current: ['id' => 7, 'items' => 1], isPost: false);
        $this->assertInstanceOf(Page::class, $this->result);
        $this->assertSame([], $this->restores);
        $this->assertSame([], $this->replaced);
        $this->assertSame([], $this->cookies);
    }

    public function testInvalidFormKeyDoesNotRestore(): void
    {
        $this->restore(formKeyValid: false);
        $this->assertSame('checkout/cart', $this->path);
        $this->assertSame([], $this->restores);
        $this->assertSame([], $this->replaced);
    }

    public function testCrossStoreLinkDoesNotRevealOrRestoreTheCart(): void
    {
        $this->restore(['store' => 2]);
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->messages);
        $this->assertSame([], $this->restores);
    }

    public function testRestoreFailureKeepsTheSessionCartAndShowsARetryMessage(): void
    {
        $this->restore(restoreError: new \RuntimeException('second save failed'));
        $this->assertSame('checkout/cart', $this->path);
        $this->assertSame([], $this->replaced);
        $this->assertSame([], $this->cookies);
        $this->assertSame(['We could not restore your saved cart. Please try again.'], $this->messages);
        $this->assertCount(1, $this->errors);
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
        $this->assertSame(['Your saved cart has been restored.'], $this->messages);
    }

    /** @dataProvider unavailable */
    #[\PHPUnit\Framework\Attributes\DataProvider('unavailable')]
    public function testOwnerSeesNoLongerAvailable(array $cart): void
    {
        $this->restore($cart);
        $this->assertSame('checkout/cart', $this->path);
        $this->assertSame(
            ['This reminder link is no longer available. You can continue shopping with your current cart.'],
            $this->messages
        );
        $this->assertSame([], $this->replaced);
    }

    public static function unavailable(): array
    {
        return [
            'ordered' => [['active' => 0]],
            'emptied' => [['items' => 0]],
            'quote expired under Magento lifetime' => [['updated' => 'expired']],
            'link older than 7 days' => [['reminded' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)]],
            'never reminded' => [['reminded' => null]],
            'invalid timestamp' => [['reminded' => 'invalid']],
            'future timestamp' => [['reminded' => gmdate('Y-m-d H:i:s', time() + 3600)]],
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
        $this->assertCount(1, $this->replaced);
        $this->assertSame($current['id'] ?? (int)$quote->getId(), (int)$this->replaced[0]->getId());
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
        $this->assertSame([[42, 7, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 0]], $this->restores);
        $this->assertSame(7, (int)$this->replaced[0]->getId());
        $this->assertSame(['Your saved cart has been restored.'], $this->messages);
        $this->assertArrayHasKey('cart', json_decode($this->cookies['section_data_ids'], true));
    }

    /** @dataProvider malformedRequests */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRequests')]
    public function testMalformedLinkDoesNotReadOrChangeCarts(mixed $id, mixed $token): void
    {
        $this->restore(token: $token, isPost: false, quoteId: $id);
        $this->assertSame('', $this->path);
        $this->assertSame(0, $this->lookups);
        $this->assertSame([], $this->restores);
        $this->assertSame([], $this->replaced);
    }

    public static function malformedRequests(): array
    {
        $token = str_repeat('a', 32);
        return ['array id' => [[42], $token], 'array token' => ['42', [$token]],
            'zero' => ['0', $token], 'negative' => ['-1', $token],
            'overflow' => [str_repeat('9', 30), $token], 'non-numeric' => ['42x', $token],
            'short token' => ['42', 'forged'], 'oversized token' => ['42', str_repeat('a', 33)]];
    }

    public function testArrayFormKeyIsRejectedWithoutCallingMagentoStringComparison(): void
    {
        $this->restore(formKey: ['key']);
        $this->assertSame('checkout/cart', $this->path);
        $this->assertSame(0, $this->lookups);
        $this->assertSame([], $this->restores);
    }
}
