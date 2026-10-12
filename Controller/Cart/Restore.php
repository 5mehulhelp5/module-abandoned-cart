<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Controller\Cart;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;

/**
 * /cartreminder/cart/restore?q={cart id}&t={signature} - the "continue shopping" link in the reminder.
 * Invalid signature -> homepage. Only the browser that gave the consent gets the cart back - its
 * browser id (CommerceKings_Consent) must match the one stored on the cart. Anyone else (forwarded email,
 * another device) -> homepage, so nobody sees another person's cart, email or address.
 * The link expires Config::RESTORE_LINK_DAYS after sending (a "no longer available" notice for the owner).
 * Customer cart (with a match): ask to log in - Magento loads it on login. Guest cart:
 *  - browser has no cart (or an empty one): it takes over the saved cart;
 *  - browser already has items: the saved cart is MERGED into it (Magento's own Quote::merge, as on
 *    login), then closed - nothing the visitor already had is lost, and it is not reminded again.
 */
class Restore implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly LinkSignature $signature,
        private readonly BrowserIdentityInterface $browser,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly ManagerInterface $messageManager,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory
    ) {
    }

    public function execute(): Redirect
    {
        $redirect = $this->redirectFactory->create();
        $quoteId = (int)$this->request->getParam('q');
        try {
            $quote = $quoteId ? $this->cartRepository->get($quoteId) : null;
        } catch (\Exception) {
            $quote = null;
        }
        if (!$quote || !$this->signature->isValid('restore', $quoteId, (string)$this->request->getParam('t'))) {
            return $redirect->setPath('');
        }
        // only the browser that gave the consent - guests and customers alike (checked first, so a
        // stranger can't even tell whether the cart was ordered)
        if (!$this->isSameBrowser((string)$quote->getData('ck_abandoned_browser_hash'))) {
            return $redirect->setPath('');
        }
        if (!$quote->getIsActive() || !$quote->getItemsCount() || !$this->isLinkFresh((string)$quote->getData('ck_abandoned_reminded_at'))) {
            $this->messageManager->addNoticeMessage(__('This cart is no longer available.'));
            return $redirect->setPath('checkout/cart');
        }
        $id = (int)$quote->getId();
        if ($quote->getCustomerId()) {
            if ((int)$this->customerSession->getCustomerId() === (int)$quote->getCustomerId()) {
                return $redirect->setPath('checkout/cart');
            }
            $this->messageManager->addNoticeMessage(__('Sign in to continue shopping – your cart is waiting for you.'));
            return $redirect->setPath('customer/account/login');
        }
        $current = $this->checkoutSession->getQuoteId() ? $this->checkoutSession->getQuote() : null;
        if ($current && (int)$current->getId() === $id) {
            return $redirect->setPath('checkout/cart');
        }
        if (!$current || !$current->getItemsCount()) {
            $this->checkoutSession->replaceQuote($quote);
            $this->refreshMiniCart();
            return $redirect->setPath('checkout/cart');
        }
        // merge the saved cart into the one this browser already has, then close the saved one
        $current->merge($quote)->collectTotals();
        $this->cartRepository->save($current);
        $quote->setIsActive(false);
        $this->cartRepository->save($quote);
        $this->refreshMiniCart();
        $this->messageManager->addSuccessMessage(__('We have added the products from your saved cart to your cart.'));
        return $redirect->setPath('checkout/cart');
    }

    /**
     * The mini cart is Magento's customer-data "cart" section, cached in the browser. It is only
     * refreshed after POST requests or when the section_data_ids cookie disagrees with the cached
     * copy - a restore is a plain GET link, so change the cookie's "cart" entry to force a reload.
     */
    private function refreshMiniCart(): void
    {
        $ids = json_decode((string)$this->cookieManager->getCookie('section_data_ids'), true);
        $ids = is_array($ids) ? $ids : [];
        $ids['cart'] = time() + 1;
        $this->cookieManager->setPublicCookie(
            'section_data_ids',
            (string)json_encode($ids),
            $this->cookieMetadataFactory->createPublicCookieMetadata()->setPath('/')->setDuration(86400)
        );
    }

    /** The link works RESTORE_LINK_DAYS after the reminder was sent (stored in UTC). */
    private function isLinkFresh(string $remindedAt): bool
    {
        if ($remindedAt === '') {
            return false;
        }
        $sent = new \DateTimeImmutable($remindedAt, new \DateTimeZone('UTC'));
        return $sent->modify('+' . Config::RESTORE_LINK_DAYS . ' days') > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** This browser's id (CommerceKings_Consent) == the one the cart was captured in. */
    private function isSameBrowser(string $cartBrowserHash): bool
    {
        return $cartBrowserHash !== '' && hash_equals($cartBrowserHash, $this->browser->hash());
    }
}
