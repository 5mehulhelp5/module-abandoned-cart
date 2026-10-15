<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Controller\Cart;

use CommerceKings\AbandonedCart\Model\CartRestore;
use CommerceKings\AbandonedCart\Model\RestoreAccess;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use CommerceKings\AbandonedCart\Controller\RejectsMalformedFormKeys;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfigInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/** GET validates the reminder link and shows a confirmation; only a form-key POST changes carts. */
class Restore implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    use RejectsMalformedFormKeys;
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
        private readonly LoggerInterface $logger,
        private readonly Json $json,
        private readonly SessionConfigInterface $sessionConfig
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $id = $this->request->getParam('q');
        $token = $this->request->getParam('t');
        if ((!is_string($id) && !is_int($id)) || !is_string($token)
            || !preg_match('/^[a-f0-9]{32}$/D', $token)
        ) {
            return $redirect->setPath('');
        }
        $quoteId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($quoteId === false || !$this->access->isSigned($quoteId, $token)) {
            return $redirect->setPath('');
        }
        if ($this->request->isPost() && (!is_string($this->request->getParam('form_key'))
            || !$this->formKeyValidator->validate($this->request))
        ) {
            return $redirect->setPath('checkout/cart');
        }
        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (\Exception) {
            $quote = null;
        }
        if (!$quote instanceof Quote || !$this->access->isOwner($quote, $token)) {
            return $redirect->setPath('');
        }
        if (!$this->access->isAvailable($quote)) {
            $this->messageManager->addNoticeMessage(
                __('This reminder link is no longer available. You can continue shopping with your current cart.')
            );
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
        $value = $this->cookieManager->getCookie('section_data_ids');
        $cartVersion = time() + 1;
        try {
            $ids = is_string($value) ? $this->json->unserialize($value) : [];
            $ids = is_array($ids) ? $ids : [];
            $ids['cart'] = $cartVersion;
            $json = $this->json->serialize($ids);
        } catch (\InvalidArgumentException) {
            // Invalid or unencodable customer-data values must not undo a successful restore.
            $json = $this->json->serialize(['cart' => $cartVersion]);
        }
        // Magento customer-data stores section_data_ids at the root, independently of the session path.
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setPath('/')
            ->setDomain($this->sessionConfig->getCookieDomain())
            ->setSecure($this->request->isSecure())
            ->setHttpOnly(false);
        $lifetime = $this->sessionConfig->getCookieLifetime();
        if ($lifetime > 0) {
            $metadata->setDuration($lifetime);
        }
        $this->cookieManager->setPublicCookie(
            'section_data_ids',
            (string)$json,
            $metadata
        );
    }
}
