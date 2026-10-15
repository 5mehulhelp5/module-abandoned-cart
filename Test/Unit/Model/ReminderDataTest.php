<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Api\Data\UnsubscribeContext;
use CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface;
use CommerceKings\AbandonedCart\Model\ConsentUnsubscribeLink;
use CommerceKings\Consent\Api\WithdrawalLinkInterface;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Product\Configuration as ProductConfiguration;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\Escaper;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use PHPUnit\Framework\TestCase;

class ReminderDataTest extends TestCase
{
    private ?UnsubscribeLinkInterface $unsubscribe = null;

    use DbTestTrait;

    private array $urls = [];
    private array $imageProducts = [];

    private function data(array $customOptions = []): ReminderData
    {
        $image = $this->mockWith(ImageHelper::class, ['init' => self::$SELF, 'getUrl' => 'https://shop/img.jpg']);
        $image->method('init')->willReturnCallback(function ($product) use ($image) {
            $this->imageProducts[] = $product;
            return $image;
        });
        $configuration = $this->createStub(ProductConfiguration::class);
        $configuration->method('getCustomOptions')->willReturn($customOptions);
        $price = $this->createStub(PriceHelper::class);
        $price->method('currency')->willReturnCallback(fn ($amount) => '€' . number_format((float)$amount, 2, '.', ','));
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function ($route, $params = []) {
            $this->urls[$route] = $params;
            return 'https://shop/' . $route . (isset($params['_query']) ? '?' . http_build_query($params['_query']) : '');
        });
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES));
        $escaper->method('escapeUrl')->willReturnCallback(fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES));
        $withdrawal = $this->createStub(WithdrawalLinkInterface::class);
        $withdrawal->method('getUrl')->willReturnCallback(fn ($id) => "https://shop/ckconsent/optout?c=$id&t=o");
        $signature = $this->createStub(LinkSignature::class);
        $signature->method('sign')->willReturnCallback(fn ($purpose, $id) => "$purpose-$id");
        return new ReminderData($image, $configuration, $price, $url, $escaper,
            $this->unsubscribe ?? new ConsentUnsubscribeLink($withdrawal), $signature);
    }

    private function quote(array $items = [], ?string $firstname = 'Jane', ?string $billingName = null): Quote
    {
        $billing = $this->mockWith(Address::class, ['getFirstname' => $billingName]);
        return $this->mockWith(Quote::class, [
            'getId' => 42, 'getStoreId' => 1, 'getCustomerId' => null, 'getCustomerFirstname' => $firstname, 'getCustomerEmail' => 'a@test.com',
            'getBillingAddress' => $billing, 'getAllVisibleItems' => $items, 'getGrandTotal' => 2400.0,
        ]);
    }

    private function item(string $name): Item
    {
        $product = $this->createStub(Product::class);
        return $this->mockWith(Item::class, [
            'getProduct' => $product, 'getOptionByCode' => null, 'getProductType' => 'simple',
            'getName' => $name, 'getQty' => 1, 'getPrice' => 2400.0,
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

    public function testTotalAndGreeting(): void
    {
        $vars = $this->data()->build($this->quote(), 'cid-1');
        $this->assertSame('€2,400.00', $vars['grand_total']);
        $this->assertSame('Jane', $vars['customer_name']);
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
        $this->assertSame('cid-1', $seen->consentId);
        $this->assertSame(9, $seen->getAttribute('subscription_id'));
        $this->assertSame('a@test.com', $seen->getAttribute('email'));
        $this->assertArrayHasKey('quote_id', (fn () => $this->attributes)->call($seen));
        $this->assertNull($seen->getAttribute('not_there'), 'a missing attribute is null, never an error');
    }
}
