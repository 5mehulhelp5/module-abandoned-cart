<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Product\Configuration as ProductConfiguration;
use Magento\Framework\Escaper;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;

/**
 * Template variables for the reminder email: greeting name, the cart items (picture, name,
 * chosen options, qty, price) as email-safe inline-styled HTML, the total and the restore link.
 * Runs inside store emulation (frontend URLs, store currency, theme images).
 */
class ReminderData
{
    /**
     * Custom option types shown in the email: only the store's own predefined choices. Free text
     * ("Naslov na korici"), file names and the like come from the visitor - anyone can create a
     * cart with any text and any recipient, so they must never reach an email we send.
     */
    private const SAFE_OPTION_TYPES = ['drop_down', 'radio', 'checkbox', 'multiple'];

    // no font of its own: the item rows take the email template's font
    private const FONT = '';

    public function __construct(
        private readonly ImageHelper $imageHelper,
        private readonly ProductConfiguration $productConfiguration,
        private readonly PriceHelper $priceHelper,
        private readonly UrlInterface $url,
        private readonly Escaper $escaper,
        private readonly OptOut $optOut,
        private readonly LinkSignature $signature
    ) {
    }

    /** @param Quote $quote */
    public function build(CartInterface $quote): array
    {
        $this->url->setScope($quote->getStoreId());
        return [
            'customer_name' => $this->greetingName((string)($quote->getCustomerFirstname() ?: $quote->getBillingAddress()->getFirstname())),
            'items_html' => $this->itemsHtml($quote),
            'grand_total' => $this->price((float)$quote->getGrandTotal()),
            'restore_url' => $this->url->getUrl('cartreminder/cart/restore', [
                '_query' => [
                    'q' => (int)$quote->getId(),
                    't' => $this->signature->sign('restore', (int)$quote->getId()),
                ],
                '_nosid' => true,
            ]),
            'store_url' => $this->url->getUrl('', ['_nosid' => true]),
            'contact_url' => $this->url->getUrl('contact', ['_nosid' => true]),
            'optout_url' => $this->optOut->url((int)$quote->getId()),
        ];
    }

    private function itemsHtml(Quote $quote): string
    {
        $rows = '';
        /** @var Item $item */
        foreach ($quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $child = $item->getOptionByCode('simple_product')?->getProduct();
            $imageProduct = ($child && $child->getThumbnail() && $child->getThumbnail() !== 'no_selection') ? $child : $product;
            $image = $this->imageHelper->init($imageProduct, 'cart_page_product_thumbnail')->getUrl();

            // configurable choices (e.g. the cover) + custom options (pages, inserts, title)
            $options = $item->getProductType() === 'configurable'
                ? (array)$product->getTypeInstance()->getSelectedAttributesInfo($product)
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
                . (float)$item->getQty() . ' × ' . $this->price((float)$item->getPrice()) . '</td>'
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

    private function price(float $amount): string
    {
        return (string)$this->priceHelper->currency($amount, true, false);
    }
}
