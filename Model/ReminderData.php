<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\RecipientBlockInterface;
use Magento\Framework\App\ObjectManager;
use CommerceKings\AbandonedCart\Api\Data\UnsubscribeContext;
use CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface;
use CommerceKings\Notification\Api\SubscriberInterface;
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
class ReminderData
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

    private readonly RecipientBlockInterface $blocks;

    public function __construct(
        private readonly ImageHelper $imageHelper,
        private readonly ProductConfiguration $productConfiguration,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly CurrencyFactory $currencyFactory,
        private readonly TaxConfig $taxConfig,
        private readonly UrlInterface $url,
        private readonly Escaper $escaper,
        private readonly UnsubscribeLinkInterface $unsubscribeLinks,
        private readonly LinkSignature $signature,
        private readonly SubscriberInterface $subscriber,
        ?RecipientBlockInterface $blocks = null
    ) {
        $this->blocks = $blocks ?? ObjectManager::getInstance()->get(RecipientBlockInterface::class);
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
            'restore_url' => $this->restoreUrl($quote),
            'store_url' => $this->url->getUrl('', ['_nosid' => true]),
            'contact_url' => $this->url->getUrl('contact', ['_nosid' => true]),
            'optout_url' => $this->unsubscribeLinks->getUrl(new UnsubscribeContext($permissionRef, (int)$quote->getStoreId(),
                $context + ['quote_id' => (int)$quote->getId(),
                    'customer_id' => $quote->getCustomerId() ? (int)$quote->getCustomerId() : null])),
            // Notification's "stop these emails" link (do not contact); '' when the shop turned it off
            'block_url' => isset($context['email']) && is_string($context['email'])
                ? $this->blocks->getBlockUrl(Channel::EMAIL, $context['email'], (int)$quote->getStoreId()) : '',
        ];
    }

    /**
     * The signed restore link only when the subscriber can be identified (RestoreAccess hands the cart to
     * that visitor only); otherwise Magento's cart page - the visitor's own session or account cart.
     */
    private function restoreUrl(Quote $quote): string
    {
        if (!$this->subscriber->identifiesVisitor()) {
            return $this->url->getUrl('checkout/cart', ['_nosid' => true]);
        }
        return $this->url->getUrl('cartreminder/cart/restore', [
            '_query' => [
                'q' => (int)$quote->getId(),
                't' => $this->signature->sign('restore', (int)$quote->getId()),
            ],
            '_nosid' => true,
        ]);
    }

    private function itemsHtml(Quote $quote): string
    {
        $rows = '';
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
            $optionLines = '';
            foreach ($options as $option) {
                $value = is_array($option['value'] ?? null) ? implode(', ', $option['value']) : (string)($option['value'] ?? '');
                $value = trim(strip_tags(html_entity_decode($value)));
                if ($value === '') {
                    continue;
                }
                $optionLines .= '<br>' . $this->escaper->escapeHtml($option['label'] ?? '') . ': ' . $this->escaper->escapeHtml($value);
            }

            $rows .= '<tr>'
                . '<td width="72" style="padding: 12px 12px 12px 0; vertical-align: top; border-bottom: 1px solid #eeeeee;">'
                . '<img src="' . $this->escaper->escapeUrl($image) . '" width="64" height="64" alt="" style="display: block; border-radius: 8px; border: 1px solid #eeeeee;"></td>'
                . '<td style="' . self::FONT . ' padding: 12px 12px 12px 0; vertical-align: top; border-bottom: 1px solid #eeeeee; color: #222529; font-size: 15px; line-height: 22px;">'
                . '<strong>' . $this->escaper->escapeHtml($item->getName()) . '</strong>'
                . '<span style="color: #77797c; font-size: 13px; line-height: 20px;">' . $optionLines . '</span></td>'
                . '<td align="right" style="' . self::FONT . ' padding: 12px 0; vertical-align: top; border-bottom: 1px solid #eeeeee; color: #222529; font-size: 15px; line-height: 22px; white-space: nowrap;">'
                . (float)$item->getQty() . ' × ' . $this->unitPrice($item, $quote) . '</td>'
                . '</tr>';
        }
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%;">' . $rows . '</table>';
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
