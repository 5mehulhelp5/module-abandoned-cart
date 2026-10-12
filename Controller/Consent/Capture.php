<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Controller\Consent;

use CommerceKings\AbandonedCart\Model\CustomerConsentCapture;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use CommerceKings\Consent\Controller\RejectsMalformedFormKeys;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;

/**
 * POST /cartreminder/consent/capture (valid form key required, including AJAX) - logged-in customer on checkout
 * (js/customer-consent.js). Cart and customer come from the session; the consent from CommerceKings_Consent.
 * Guests use the checkout email endpoint instead (GuestEmailCapture).
 */
class Capture implements HttpPostActionInterface, CsrfAwareActionInterface
{
    use RejectsMalformedFormKeys;
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CustomerConsentCapture $capture,
        private readonly RequestInterface $request,
        private readonly Validator $formKeyValidator
    ) {
    }

    public function execute(): Json
    {
        $key = $this->request->getParam('form_key');
        if (!is_string($key) || !$this->formKeyValidator->validate($this->request)) {
            return $this->jsonFactory->create()->setHttpResponseCode(403)->setData(['stored' => false]);
        }
        $stored = $this->customerSession->isLoggedIn() && $this->capture->execute(
            (int)$this->checkoutSession->getQuoteId(),
            (int)$this->customerSession->getCustomerId()
        );
        return $this->jsonFactory->create()->setData(['stored' => $stored]);
    }
}
