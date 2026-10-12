<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\OptOut;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Product\Configuration as ProductConfiguration;
use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\TestCase;

class ReminderDataTest extends TestCase
{
    use DbTestTrait;

    private array $urls = [];

    private function data(array $customOptions = []): ReminderData
    {
        $image = $this->mockWith(ImageHelper::class, ['init' => self::$SELF, 'getUrl' => 'https://shop/img.jpg']);
        $configuration = $this->createMock(ProductConfiguration::class);
        $configuration->method('getCustomOptions')->willReturn($customOptions);
        $price = $this->createMock(PriceHelper::class);
        $price->method('currency')->willReturnCallback(fn ($amount) => number_format((float)$amount, 2, ',', '.') . ' RSD');
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function ($route, $params = []) {
            $this->urls[$route] = $params;
            return 'https://shop/' . $route . (isset($params['_query']) ? '?' . http_build_query($params['_query']) : '');
        });
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES));
        $escaper->method('escapeUrl')->willReturnCallback(fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES));
        $optOut = $this->createMock(OptOut::class);
        $optOut->method('url')->willReturnCallback(fn ($id) => "https://shop/cartreminder/optout?q=$id&t=o");
        $signature = $this->createMock(LinkSignature::class);
        $signature->method('sign')->willReturnCallback(fn ($purpose, $id) => "$purpose-$id");
        return new ReminderData($image, $configuration, $price, $url, $escaper, $optOut, $signature);
    }

    private function quote(array $items = [], ?string $firstname = 'Jelena', ?string $billingName = null): Quote
    {
        $billing = $this->mockWith(Address::class, ['getFirstname' => $billingName]);
        return $this->mockWith(Quote::class, [
            'getId' => 42, 'getStoreId' => 1, 'getCustomerFirstname' => $firstname, 'getCustomerEmail' => 'a@test.com',
            'getBillingAddress' => $billing, 'getAllVisibleItems' => $items, 'getGrandTotal' => 2400.0,
        ]);
    }

    private function item(string $name): Item
    {
        $product = $this->createMock(Product::class);
        return $this->mockWith(Item::class, [
            'getProduct' => $product, 'getOptionByCode' => null, 'getProductType' => 'simple',
            'getName' => $name, 'getQty' => 1, 'getPrice' => 2400.0,
        ]);
    }

    public function testLinksAreSignedAndCarryNoCartKeyOrEmail(): void
    {
        $vars = $this->data()->build($this->quote());
        $this->assertSame('https://shop/cartreminder/cart/restore?q=42&t=restore-42', $vars['restore_url']);
        $this->assertSame('https://shop/cartreminder/optout?q=42&t=o', $vars['optout_url']);
        $this->assertStringNotContainsString('a@test.com', $vars['restore_url'] . $vars['optout_url']);
        $this->assertSame('https://shop/contact', $vars['contact_url']);
        $this->assertTrue($this->urls['cartreminder/cart/restore']['_nosid']);
    }

    public function testTotalAndGreeting(): void
    {
        $vars = $this->data()->build($this->quote());
        $this->assertSame('2.400,00 RSD', $vars['grand_total']);
        $this->assertSame('Jelena', $vars['customer_name']);
    }

    public function testGreetingFallsBackToBillingNameThenEmpty(): void
    {
        $this->assertSame('Ana', $this->data()->build($this->quote([], null, 'Ana'))['customer_name']);
        $this->assertSame('', $this->data()->build($this->quote([], null, null))['customer_name']);
    }

    public function testItemsWithOptionsQtyPriceAndEscaping(): void
    {
        $html = $this->data([
            ['label' => 'Broj strana', 'value' => '150', 'option_type' => 'radio'],
            ['label' => 'Umetci', 'value' => ['Kalendar', 'Beleške'], 'option_type' => 'multiple'],
            ['label' => 'Prazno', 'value' => '', 'option_type' => 'drop_down'],
        ])->build($this->quote([$this->item('Moji recepti <script>x</script>')]))['items_html'];

        $this->assertStringContainsString('Moji recepti &lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<br>Broj strana: 150', $html);
        $this->assertStringContainsString('<br>Umetci: Kalendar, Beleške', $html);
        $this->assertStringNotContainsString('Prazno', $html, 'empty options are left out');
        $this->assertStringContainsString('1 × 2.400,00 RSD', $html);
        $this->assertStringContainsString('src="https://shop/img.jpg"', $html);
    }

    public function testVisitorFreeTextNeverReachesTheEmail(): void
    {
        // anyone can create a cart with any text and any recipient - only the store's own choices are shown
        $html = $this->data([
            ['label' => 'Naslov na korici', 'value' => 'Vas nalog je blokiran, idite na evil.example', 'option_type' => 'field'],
            ['label' => 'Napomena', 'value' => 'phishing text', 'option_type' => 'area'],
            ['label' => 'Logo', 'value' => 'evil.example.png', 'option_type' => 'file'],
            ['label' => 'Nepoznato', 'value' => 'x', 'option_type' => null],
            ['label' => 'Broj strana', 'value' => '150', 'option_type' => 'radio'],
            ['label' => 'Korice', 'value' => 'Plava', 'option_type' => 'checkbox'],
        ])->build($this->quote([$this->item('Planer')]))['items_html'];
        foreach (['Naslov na korici', 'evil.example', 'Napomena', 'phishing', 'Logo', 'Nepoznato'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertStringContainsString('Broj strana: 150', $html);
        $this->assertStringContainsString('Korice: Plava', $html);
    }

    /** @dataProvider greetings */
    public function testGreetingIsOneShortWordOfLetters(string $typed, string $shown): void
    {
        $this->assertSame($shown, $this->data()->build($this->quote([], $typed))['customer_name']);
    }

    public static function greetings(): array
    {
        return [
            'plain' => ['Jelena', 'Jelena'],
            'first word only' => ['Jelena Marija Test', 'Jelena'],
            'Serbian letters' => ['Đorđe', 'Đorđe'],
            'hyphen, apostrophe' => ["Anne-Marie", 'Anne-Marie'],
            'sentence' => ['Vas nalog je blokiran', 'Vas'],
            'link' => ['evil.example', ''],
            'url' => ['https://evil.example', ''],
            'digits' => ['Jelena123', ''],
            'too long' => [str_repeat('a', 21), ''],
            'markup' => ['<b>Jelena</b>', ''],
            'spaces only' => ['   ', ''],
        ];
    }

    public function testEmptyCartGivesEmptyTable(): void
    {
        $html = $this->data()->build($this->quote())['items_html'];
        $this->assertStringStartsWith('<table', $html);
        $this->assertStringNotContainsString('<tr>', $html);
    }
}
