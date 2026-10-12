<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Controller\Optout;

use CommerceKings\AbandonedCart\Model\OptOut;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\PageFactory;

/**
 * /cartreminder/optout?q=&t= - the unsubscribe link in the reminder email. One click: opening
 * the link withdraws the consent the cart was captured with and shows the confirmation. Already
 * opted out (the cart no longer carries a consent), an invalid link or a cart already cleaned up
 * -> homepage, nothing to reveal.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly OptOut $optOut
    ) {
    }

    public function execute(): ResultInterface
    {
        $quoteId = (int)$this->request->getParam('q');
        if (!$this->optOut->isValid($quoteId, (string)$this->request->getParam('t'))
            || !$this->optOut->withdraw($quoteId)
        ) {
            return $this->redirectFactory->create()->setPath('');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Odjava od podsetnika o korpi'));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        return $page;
    }
}
