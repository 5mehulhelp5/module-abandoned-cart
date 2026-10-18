<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\AbandonedCart\Api\Data\UnsubscribeContext;
use CommerceKings\AbandonedCart\Api\CartDataInterface;
use CommerceKings\AbandonedCart\Api\Data\CartFacts;
use CommerceKings\AbandonedCart\Api\RestoreLinkInterface;
use CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Product\Configuration as ProductConfiguration;
use Magento\Framework\Escaper;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;

/**
 * Template variables for the reminder email: greeting name, the cart items (picture, name,
 * chosen options, qty, price) as email-safe inline-styled HTML, the total and the restore link.
 * Runs inside store emulation (frontend URLs, store currency, theme images).
 */
class ReminderData implements CartDataInterface
{
    /**
     * Custom option types shown in the email: only the store's own predefined choices. Free text
     * (such as an engraved message), file names and the like come from the visitor - anyone can create a
     * cart with any text and any recipient, so they must never reach an email we send.
     */
    private const SAFE_OPTION_TYPES = ['drop_down', 'radio', 'checkbox', 'multiple'];

    // no font of its own: the item rows take the email template's font
    private const FONT = '';

    /** @var array<string, Currency> */
    private array $currencies = [];


    public function __construct(
        private readonly ImageHelper $imageHelper,
        private readonly ProductConfiguration $productConfiguration,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly CurrencyFactory $currencyFactory,
        private readonly TaxConfig $taxConfig,
        private readonly UrlInterface $url,
        private readonly Escaper $escaper,
        private readonly UnsubscribeLinkInterface $unsubscribeLinks,
        private readonly RestoreLinkInterface $restoreLinks,
        private readonly StopListInterface $stopList
    ) {
    }

    /** The cart's facts for a reminder on another channel: the same name, items and prices the email shows. */
    public function get(Quote $quote): CartFacts
    {
        $this->url->setScope($quote->getStoreId());
        $name = (string)($quote->getCustomerFirstname() ?: $quote->getBillingAddress()->getFirstname());
        return new CartFacts(
            $this->greetingName($name),
            array_map(fn (array $item) => ['name' => $item['name'], 'qty' => $item['qty'], 'price' => $item['price'],
                'image' => $item['image'], 'options' => array_map(fn ($option) => $option[0] . ': ' . $option[1],
                    $item['options'])], $this->items($quote)),
            $this->price((float)$quote->getGrandTotal(), $quote),
            $this->url->getUrl('', ['_nosid' => true])
        );
    }

    /**
     * @param string $permissionRef the permission reference of the cart's subscription (its unsubscribe link)
     * @param array<string, mixed> $context more for the unsubscribe link adapter (subscription_id, email)
     */
    public function build(Quote $quote, string $permissionRef, array $context = []): array
    {
        $this->url->setScope($quote->getStoreId());
        return [
            'customer_name' => $this->greetingName((string)($quote->getCustomerFirstname() ?: $quote->getBillingAddress()->getFirstname())),
            'items_html' => $this->itemsHtml($quote),
            'grand_total' => $this->price((float)$quote->getGrandTotal(), $quote),
            'restore_url' => $this->restoreLinks->getUrl($quote, CartReminder::CODE),
            'store_url' => $this->url->getUrl('', ['_nosid' => true]),
            'contact_url' => $this->url->getUrl('contact', ['_nosid' => true]),
            'optout_url' => $this->unsubscribeLinks->getUrl(new UnsubscribeContext($permissionRef, (int)$quote->getStoreId(),
                $context + ['quote_id' => (int)$quote->getId(),
                    'customer_id' => $quote->getCustomerId() ? (int)$quote->getCustomerId() : null])),
            // Notification's "stop these emails" link (do not contact); '' when the shop turned it off
            'stop_url' => isset($context['email']) && is_string($context['email'])
                ? $this->stopList->getStopUrl(Channel::EMAIL, $context['email'], (int)$quote->getStoreId()) : '',
        ];
    }

    private function itemsHtml(Quote $quote): string
    {
        $rows = '';
        foreach ($this->items($quote) as $item) {
            $optionLines = '';
            foreach ($item['options'] as [$label, $value]) {
                $optionLines .= '<br>' . $this->escaper->escapeHtml($label) . ': ' . $this->escaper->escapeHtml($value);
            }

            $rows .= '<tr>'
                . '<td width="72" style="padding: 12px 12px 12px 0; vertical-align: top; border-bottom: 1px solid #eeeeee;">'
                . '<img src="' . $this->escaper->escapeUrl($item['image']) . '" width="64" height="64" alt="" style="display: block; border-radius: 8px; border: 1px solid #eeeeee;"></td>'
                . '<td style="' . self::FONT . ' padding: 12px 12px 12px 0; vertical-align: top; border-bottom: 1px solid #eeeeee; color: #222529; font-size: 15px; line-height: 22px;">'
                . '<strong>' . $this->escaper->escapeHtml($item['name']) . '</strong>'
                . '<span style="color: #77797c; font-size: 13px; line-height: 20px;">' . $optionLines . '</span></td>'
                . '<td align="right" style="' . self::FONT . ' padding: 12px 0; vertical-align: top; border-bottom: 1px solid #eeeeee; color: #222529; font-size: 15px; line-height: 22px; white-space: nowrap;">'
                . $item['qty'] . ' × ' . $item['price'] . '</td>'
                . '</tr>';
        }
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%;">' . $rows . '</table>';
    }

    /**
     * The cart's visible items as plain values: name, quantity, unit price as the cart shows it, thumbnail, and the
     * option lines a visitor cannot type ([label, value], no markup).
     *
     * @return array<int, array{name: string, qty: float, price: string, image: string, options: array<int, array{0: string, 1: string}>}>
     */
    private function items(Quote $quote): array
    {
        $items = [];
        /** @var Item $item */
        foreach ($quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $simpleOption = $item->getOptionByCode('simple_product');
            $child = $simpleOption instanceof Option ? $simpleOption->getProduct() : null;
            $imageProduct = ($child && $child->getThumbnail() && $child->getThumbnail() !== 'no_selection') ? $child : $product;
            $image = $this->imageHelper->init($imageProduct, 'cart_page_product_thumbnail')->getUrl();

            // configurable choices (e.g. the cover) + custom options (pages, inserts, title)
            $options = $item->getProductType() === 'configurable'
                ? (array)($product->getTypeInstance()->getOrderOptions($product)['attributes_info'] ?? [])
                : [];
            $options = array_merge($options, array_filter(
                $this->productConfiguration->getCustomOptions($item),
                fn ($option) => in_array($option['option_type'] ?? '', self::SAFE_OPTION_TYPES, true)
            ));
            $lines = [];
            foreach ($options as $option) {
                $value = is_array($option['value'] ?? null) ? implode(', ', $option['value']) : (string)($option['value'] ?? '');
                $value = trim(strip_tags(html_entity_decode($value)));
                if ($value === '') {
                    continue;
                }
                $lines[] = [(string)($option['label'] ?? ''), $value];
            }
            $items[] = ['name' => (string)$item->getName(), 'qty' => (float)$item->getQty(),
                'price' => $this->unitPrice($item, $quote), 'image' => (string)$image, 'options' => $lines];
        }
        return $items;
    }

    /**
     * The greeting uses only the first word of the name, letters only, at most 20 characters -
     * the name is typed by whoever created the cart, so a sentence or a link must not get through.
     */
    private function greetingName(string $name): string
    {
        $first = explode(' ', trim(preg_replace('/\s+/u', ' ', $name)))[0];
        return preg_match('/^[\p{L}\'-]{1,20}$/u', $first) ? $first : '';
    }

    /**
     * The unit price as Magento's cart shows it (Tax > Shopping Cart Display Settings > Display Prices: excluding tax,
     * including tax, or both), in the cart's currency. Like Magento: including tax is price_incl_tax (cart currency);
     * excluding tax is the item price, which Magento keeps in the base currency, converted at the cart's own rate.
     */
    private function unitPrice(Item $item, Quote $quote): string
    {
        $store = $quote->getStoreId();
        $rate = (float)$quote->getBaseToQuoteRate() ?: 1.0;
        $excl = $this->price(round((float)$item->getPrice() * $rate, 2), $quote);
        $incl = $this->price((float)$item->getPriceInclTax(), $quote);
        if ($this->taxConfig->displayCartPricesBoth($store)) {
            return $this->escaper->escapeHtml(__('Excl. Tax')) . ': ' . $excl . '<br>'
                . $this->escaper->escapeHtml(__('Incl. Tax')) . ': ' . $incl;
        }
        return $this->taxConfig->displayCartPricesInclTax($store) ? $incl : $excl;
    }

    /**
     * Cart amounts (the total, price incl. tax) are already in the cart's currency (the one the shopper saw): format them in it, never convert
     * again from the base currency.
     */
    private function price(float $amount, Quote $quote): string
    {
        // a Currency, not its code: for a code without an exchange rate Magento would format in the base currency
        $code = (string)$quote->getQuoteCurrencyCode();
        $currency = $code !== '' ? $this->currencies[$code] ??= $this->currencyFactory->create()->load($code) : null;
        return (string)$this->priceCurrency->format($amount, false, PriceCurrencyInterface::DEFAULT_PRECISION,
            $quote->getStoreId(), $currency);
    }
}
