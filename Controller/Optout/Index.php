<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Controller\Optout;

use CommerceKings\AbandonedCart\Model\OptOut;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;

/**
 * Old cart-based email links redirect to Consent's signed withdrawal endpoint.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly OptOut $optOut
    ) {
    }

    public function execute(): Redirect
    {
        $url = $this->optOut->getWithdrawalUrl(
            (int)$this->request->getParam('q'),
            (string)$this->request->getParam('t')
        );
        $redirect = $this->redirectFactory->create();
        return $url ? $redirect->setUrl($url) : $redirect->setPath('');
    }
}
