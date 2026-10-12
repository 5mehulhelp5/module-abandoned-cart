<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Controller\Cart;

use CommerceKings\AbandonedCart\Model\CartRestore;
use CommerceKings\AbandonedCart\Model\RestoreAccess;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/** GET validates the reminder link and shows a confirmation; only a form-key POST changes carts. */
class Restore implements HttpGetActionInterface, HttpPostActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly RestoreAccess $access,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly ManagerInterface $messageManager,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly PageFactory $pageFactory,
        private readonly Validator $formKeyValidator,
        private readonly CartRestore $restore,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $quoteId = (int)$this->request->getParam('q');
        $token = (string)$this->request->getParam('t');
        if ($this->request->isPost() && !$this->formKeyValidator->validate($this->request)) {
            return $redirect->setPath('checkout/cart');
        }
        try {
            $quote = $quoteId ? $this->cartRepository->get($quoteId) : null;
        } catch (\Exception) {
            $quote = null;
        }
        if (!$quote instanceof Quote || !$this->access->isOwner($quote, $token)) {
            return $redirect->setPath('');
        }
        if (!$this->access->isAvailable($quote)) {
            $this->messageManager->addNoticeMessage(__('This cart is no longer available.'));
            return $redirect->setPath('checkout/cart');
        }
        if ($quote->getCustomerId()) {
            if ((int)$this->customerSession->getCustomerId() === (int)$quote->getCustomerId()) {
                return $redirect->setPath('checkout/cart');
            }
            $this->messageManager->addNoticeMessage(__('Sign in to continue shopping – your cart is waiting for you.'));
            return $redirect->setPath('customer/account/login');
        }
        if ((int)$this->checkoutSession->getQuoteId() === $quoteId) {
            return $redirect->setPath('checkout/cart');
        }
        if (!$this->request->isPost()) {
            $page = $this->pageFactory->create();
            $page->getConfig()->getTitle()->set((string)__('Restore your saved cart'));
            $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
            return $page;
        }
        try {
            $restored = $this->restore->execute($quoteId, $this->checkoutSession->getQuote(), $token,
                (int)$this->customerSession->getCustomerId());
            $this->checkoutSession->replaceQuote($restored);
            $this->refreshMiniCart();
            $this->messageManager->addSuccessMessage(__('Your saved cart has been restored.'));
        } catch (LocalizedException $error) {
            $this->messageManager->addNoticeMessage($error->getMessage());
        } catch (\Throwable $error) {
            $this->logger->error('CommerceKings_AbandonedCart: cart restore failed.', ['exception' => $error]);
            $this->messageManager->addErrorMessage(__('We could not restore your saved cart. Please try again.'));
        }
        return $redirect->setPath('checkout/cart');
    }

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
}
