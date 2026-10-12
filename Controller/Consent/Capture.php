<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Controller\Consent;

use CommerceKings\AbandonedCart\Model\CustomerConsentCapture;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST /cartreminder/consent/capture (form key checked by Magento) - logged-in customer on checkout
 * (js/customer-consent.js). Cart and customer come from the session; the consent from CommerceKings_Consent.
 * Guests use the checkout email endpoint instead (GuestEmailCapture).
 */
class Capture implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CustomerConsentCapture $capture
    ) {
    }

    public function execute(): Json
    {
        $stored = $this->customerSession->isLoggedIn() && $this->capture->execute(
            (int)$this->checkoutSession->getQuoteId(),
            (int)$this->customerSession->getCustomerId()
        );
        return $this->jsonFactory->create()->setData(['stored' => $stored]);
    }
}
