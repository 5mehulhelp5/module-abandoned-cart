<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Api\Data\UnsubscribeContext;
use CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface;
use CommerceKings\AbandonedCart\Model\SubscriberUnsubscribeLink;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Product\Configuration as ProductConfiguration;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\Escaper;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use PHPUnit\Framework\TestCase;

class ReminderDataTest extends TestCase
{
    /** whether the subscription's permission can tell who subscribed (Consent: yes) */
    private bool $identifiesVisitor = true;
    /** whether the permission has an unsubscribe link (consent bridge: yes; Notification alone: no) */
    private bool $hasLink = true;
    private ?UnsubscribeLinkInterface $unsubscribe = null;

    use DbTestTrait;

    private array $urls = [];
    private array $imageProducts = [];
    private array $formatted = [];
    private array $loaded = [];
    private string $quoteCurrency = 'EUR';
    private float $baseToQuoteRate = 1.0;
    /** Tax > Shopping Cart Display Settings > Display Prices: 1 excluding, 2 including, 3 both */
    private int $cartPriceDisplay = 1;

    private function data(array $customOptions = []): ReminderData
    {
        $image = $this->mockWith(ImageHelper::class, ['init' => self::$SELF, 'getUrl' => 'https://shop/img.jpg']);
        $image->method('init')->willReturnCallback(function ($product) use ($image) {
            $this->imageProducts[] = $product;
            return $image;
        });
        $configuration = $this->createStub(ProductConfiguration::class);
        $configuration->method('getCustomOptions')->willReturn($customOptions);
        // format() only: a conversion (convert / convertAndFormat) would return null and fail the assertions
        $price = $this->createStub(PriceCurrencyInterface::class);
        $price->method('format')->willReturnCallback(function ($amount, $container, $precision, $scope, $currency) {
            $code = $currency?->getCode();
            $this->formatted[] = [$amount, $scope, $code];
            return ['EUR' => '€', 'USD' => '$', 'CHF' => 'CHF '][$code ?? 'EUR'] . number_format((float)$amount, 2, '.', ',');
        });
        $currencies = $this->createStub(\Magento\Directory\Model\CurrencyFactory::class);
        $currencies->method('create')->willReturnCallback(function () {
            $currency = $this->createStub(\Magento\Directory\Model\Currency::class);
            $currency->method('load')->willReturnCallback(function ($code) use ($currency) {
                $this->loaded[] = $code;
                return $this->mockWith(\Magento\Directory\Model\Currency::class, ['getCode' => $code]);
            });
            return $currency;
        });
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function ($route, $params = []) {
            $this->urls[$route] = $params;
            return 'https://shop/' . $route . (isset($params['_query']) ? '?' . http_build_query($params['_query']) : '');
        });
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES));
        $escaper->method('escapeUrl')->willReturnCallback(fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES));
        $signature = $this->createStub(LinkSignature::class);
        $signature->method('sign')->willReturnCallback(fn ($purpose, $id) => "$purpose-$id");
        $subscriber = $this->createStub(SubscriberInterface::class);
        $subscriber->method('identifiesVisitor')->willReturnCallback(fn () => $this->identifiesVisitor);
        // the default link asks the permission; the subscription's reference is the consent id cid-1
        $subscriber->method('getUnsubscribeUrl')->willReturnCallback(fn (Subscription $subscription) => $this->hasLink
            ? 'https://shop/ckconsent/optout?c=' . $subscription->permissionRef . '&t=o' : null);
        $subscriptions = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptions->method('get')->willReturnCallback(fn ($id) => new Subscription((int)$id, 'cart_reminder', 42, 1, 5, null,
            'cid-1', '2026-01-01 00:00:00'));
        $tax = $this->createStub(\Magento\Tax\Model\Config::class);
        $tax->method('displayCartPricesExclTax')->willReturnCallback(fn () => $this->cartPriceDisplay === 1);
        $tax->method('displayCartPricesInclTax')->willReturnCallback(fn () => $this->cartPriceDisplay === 2);
        $tax->method('displayCartPricesBoth')->willReturnCallback(fn () => $this->cartPriceDisplay === 3);
        return new ReminderData($image, $configuration, $price, $currencies, $tax, $url, $escaper,
            $this->unsubscribe ?? new SubscriberUnsubscribeLink($subscriptions, $subscriber),
            new \CommerceKings\AbandonedCart\Model\RestoreLink($url, $signature, $subscriber),
            $this->createStub(\CommerceKings\Notification\Api\StopListInterface::class));
    }

    private function quote(array $items = [], ?string $firstname = 'Jane', ?string $billingName = null): Quote
    {
        $billing = $this->mockWith(Address::class, ['getFirstname' => $billingName]);
        return $this->mockWith(Quote::class, [
            'getId' => 42, 'getStoreId' => 1, 'getCustomerId' => null, 'getCustomerFirstname' => $firstname, 'getCustomerEmail' => 'a@test.com',
            'getBillingAddress' => $billing, 'getAllVisibleItems' => $items, 'getGrandTotal' => 2400.0,
            'getQuoteCurrencyCode' => $this->quoteCurrency, 'getBaseToQuoteRate' => $this->baseToQuoteRate,
        ]);
    }

    private function item(string $name): Item
    {
        $product = $this->createStub(Product::class);
        return $this->mockWith(Item::class, [
            'getProduct' => $product, 'getOptionByCode' => null, 'getProductType' => 'simple',
            'getName' => $name, 'getQty' => 1, 'getPrice' => 2400.0, 'getPriceInclTax' => 2880.0,
        ]);
    }

    public function testLinksAreSignedAndCarryNoCartKeyOrEmail(): void
    {
        $vars = $this->data()->build($this->quote(), 'cid-1');
        $this->assertSame('https://shop/cartreminder/cart/restore?q=42&t=restore-42', $vars['restore_url']);
        $this->assertSame('https://shop/ckconsent/optout?c=cid-1&t=o', $vars['optout_url']);
        $this->assertStringNotContainsString('a@test.com', $vars['restore_url'] . $vars['optout_url']);
        $this->assertSame('https://shop/contact', $vars['contact_url']);
        $this->assertTrue($this->urls['cartreminder/cart/restore']['_nosid']);
    }

    /** Without a way to tell who subscribed (opt-out), nobody gets a restore link: the cart page shows the visitor's own cart. */
    public function testWithoutVisitorIdentificationTheLinkIsTheCartPage(): void
    {
        $this->identifiesVisitor = false;
        $vars = $this->data()->build($this->quote(), 'cid-1');
        $this->assertSame('https://shop/checkout/cart', $vars['restore_url']);
        $this->assertTrue($this->urls['checkout/cart']['_nosid']);
        $this->assertArrayNotHasKey('cartreminder/cart/restore', $this->urls, 'no signed link is made');
    }

    /** Notification alone has no unsubscribe link: the template leaves the line out and no one-click header is sent. */
    public function testWithoutAPermissionLinkThereIsNoUnsubscribeLink(): void
    {
        $this->hasLink = false;
        $this->assertSame('', $this->data()->build($this->quote(), '', ['subscription_id' => 9])['optout_url']);
    }

    public function testTotalAndGreeting(): void
    {
        $vars = $this->data()->build($this->quote(), 'cid-1');
        $this->assertSame('€2,400.00', $vars['grand_total']);
        $this->assertSame('Jane', $vars['customer_name']);
    }

    public function testAmountsStayInTheCartsCurrencyWithoutConversion(): void
    {
        // the shopper browsed in CHF while the base currency is EUR: the cart already holds CHF amounts
        $this->quoteCurrency = 'CHF';
        $vars = $this->data()->build($this->quote([$this->item('Bag')]), 'cid-1');
        $this->assertSame('CHF 2,400.00', $vars['grand_total']);
        $this->assertStringContainsString('1 × CHF 2,400.00', $vars['items_html']);
        $this->assertSame([[2400.0, 1, 'CHF'], [2880.0, 1, 'CHF'], [2400.0, 1, 'CHF']], $this->formatted,
            'every amount: cart currency, its store');
        $this->assertSame(['CHF'], $this->loaded, 'the currency is loaded once per email');
    }

    public function testItemPricesFollowMagentosCartDisplaySettingInTheCartsCurrency(): void
    {
        // base EUR, cart in USD at 1.10: Magento keeps the item price (excl. tax) in base, price_incl_tax in the cart's
        $this->quoteCurrency = 'USD';
        $this->baseToQuoteRate = 1.1;
        $quote = fn () => $this->quote([$this->item('Bag')]);

        $this->assertStringContainsString('1 × $2,640.00</td>', $this->data()->build($quote(), 'cid-1')['items_html'],
            'excluding tax: the base price converted at the cart\'s rate');
        $this->cartPriceDisplay = 2;
        $this->assertStringContainsString('1 × $2,880.00</td>', $this->data()->build($quote(), 'cid-1')['items_html']);
        $this->cartPriceDisplay = 3;
        $this->assertStringContainsString('1 × Excl. Tax: $2,640.00<br>Incl. Tax: $2,880.00</td>',
            $this->data()->build($quote(), 'cid-1')['items_html']);
    }

    public function testGreetingFallsBackToBillingNameThenEmpty(): void
    {
        $this->assertSame('Alice', $this->data()->build($this->quote([], null, 'Alice'), 'cid-1')['customer_name']);
        $this->assertSame('', $this->data()->build($this->quote([], null, null), 'cid-1')['customer_name']);
    }

    public function testItemsWithOptionsQtyPriceAndEscaping(): void
    {
        $html = $this->data([
            ['label' => 'Page count', 'value' => '150', 'option_type' => 'radio'],
            ['label' => 'Inserts', 'value' => ['Calendar', 'Notes'], 'option_type' => 'multiple'],
            ['label' => 'Empty', 'value' => '', 'option_type' => 'drop_down'],
        ])->build($this->quote([$this->item('Recipe book <script>x</script>')]), 'cid-1')['items_html'];

        $this->assertStringContainsString('Recipe book &lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<br>Page count: 150', $html);
        $this->assertStringContainsString('<br>Inserts: Calendar, Notes', $html);
        $this->assertStringNotContainsString('Empty', $html, 'empty options are left out');
        $this->assertStringContainsString('1 × €2,400.00', $html);
        $this->assertStringContainsString('src="https://shop/img.jpg"', $html);
    }

    public function testVisitorFreeTextNeverReachesTheEmail(): void
    {
        // anyone can create a cart with any text and any recipient - only the store's own choices are shown
        $html = $this->data([
            ['label' => 'Engraved message', 'value' => 'Your account is blocked, visit evil.example', 'option_type' => 'field'],
            ['label' => 'Comment', 'value' => 'phishing text', 'option_type' => 'area'],
            ['label' => 'Logo', 'value' => 'evil.example.png', 'option_type' => 'file'],
            ['label' => 'Unknown', 'value' => 'x', 'option_type' => null],
            ['label' => 'Page count', 'value' => '150', 'option_type' => 'radio'],
            ['label' => 'Cover', 'value' => 'Blue', 'option_type' => 'checkbox'],
        ])->build($this->quote([$this->item('Notebook')]), 'cid-1')['items_html'];
        foreach (['Engraved message', 'evil.example', 'Comment', 'phishing', 'Logo', 'Unknown'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertStringContainsString('Page count: 150', $html);
        $this->assertStringContainsString('Cover: Blue', $html);
    }

    public function testConfigurableChoicesAndChildImageAreKeptWithoutVisitorText(): void
    {
        $type = $this->createStub(AbstractType::class);
        $type->method('getOrderOptions')->willReturn([
            'attributes_info' => [['label' => 'Color', 'value' => 'Blue & white']],
            'options' => [['label' => 'Message', 'value' => 'evil.example', 'option_type' => 'field']],
        ]);
        $product = $this->createStub(Product::class);
        $product->method('getTypeInstance')->willReturn($type);
        $child = $this->mockWith(Product::class, ['getThumbnail' => 'child.jpg']);
        $option = $this->createStub(Option::class);
        $option->method('getProduct')->willReturn($child);
        $item = $this->mockWith(Item::class, [
            'getProduct' => $product, 'getOptionByCode' => $option, 'getProductType' => 'configurable',
            'getName' => 'Configurable product', 'getQty' => 1, 'getPrice' => 2400.0,
        ]);

        $html = $this->data()->build($this->quote([$item]), 'cid-1')['items_html'];
        $this->assertSame([$child], $this->imageProducts);
        $this->assertStringContainsString('Color: Blue &amp; white', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('Message', $html);
    }

    /** @dataProvider greetings */
    #[\PHPUnit\Framework\Attributes\DataProvider('greetings')]
    public function testGreetingIsOneShortWordOfLetters(string $typed, string $shown): void
    {
        $this->assertSame($shown, $this->data()->build($this->quote([], $typed), 'cid-1')['customer_name']);
    }

    public static function greetings(): array
    {
        return [
            'plain' => ['Jane', 'Jane'],
            'first word only' => ['Jane Mary Test', 'Jane'],
            'Unicode letters' => ['Zoë', 'Zoë'],
            'hyphen, apostrophe' => ["Anne-Marie", 'Anne-Marie'],
            'sentence' => ['Your account is blocked', 'Your'],
            'link' => ['evil.example', ''],
            'url' => ['https://evil.example', ''],
            'digits' => ['Jane123', ''],
            'too long' => [str_repeat('a', 21), ''],
            'markup' => ['<b>Jane</b>', ''],
            'spaces only' => ['   ', ''],
        ];
    }

    public function testEmptyCartGivesEmptyTable(): void
    {
        $html = $this->data()->build($this->quote(), 'cid-1')['items_html'];
        $this->assertStringStartsWith('<table', $html);
        $this->assertStringNotContainsString('<tr>', $html);
    }

    public function testACustomUnsubscribeLinkGetsTheWholeContext(): void
    {
        $seen = null;
        $this->unsubscribe = new class($seen) implements UnsubscribeLinkInterface {
            public function __construct(public ?UnsubscribeContext &$seen) {}
            public function getUrl(UnsubscribeContext $context): string
            {
                $this->seen = $context;
                return 'https://shop/preferences/' . $context->getAttribute('subscription_id');
            }
        };
        $vars = $this->data()->build($this->quote(), 'cid-1', ['subscription_id' => 9, 'email' => 'a@test.com']);
        $this->assertSame('https://shop/preferences/9', $vars['optout_url'], 'any link format');
        $this->assertSame('cid-1', $seen->permissionRef);
        $this->assertSame(9, $seen->getAttribute('subscription_id'));
        $this->assertSame('a@test.com', $seen->getAttribute('email'));
        $this->assertArrayHasKey('quote_id', (fn () => $this->attributes)->call($seen));
        $this->assertNull($seen->getAttribute('not_there'), 'a missing attribute is null, never an error');
    }

    /** The same facts as the email, without markup, for a reminder on another channel (CartDataInterface). */
    public function testCartFactsForOtherChannels(): void
    {
        $data = $this->data();
        $facts = $data->get($this->quote([$this->item('Notebook <b>A5</b>')]));
        $vars = $data->build($this->quote([$this->item('Notebook <b>A5</b>')]), 'cid-1');
        $this->assertSame('Jane', $facts->firstName);
        $this->assertSame($vars['grand_total'], $facts->grandTotal);
        $this->assertSame('https://shop/', $facts->storeUrl);
        $this->assertCount(1, $facts->items);
        $this->assertSame(['Notebook <b>A5</b>', 1.0], [$facts->items[0]['name'], $facts->items[0]['qty']],
            'plain values: a text or template escapes them itself');
        $this->assertStringContainsString($facts->items[0]['price'], $vars['items_html'], 'the price the email shows');
        $this->assertSame([], $facts->items[0]['options']);
    }
}
