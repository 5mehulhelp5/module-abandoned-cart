<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Controller\Optout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;

/**
 * Retired quote-only links cannot identify the original consent; never withdraw a later grant.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    public function execute(): Redirect
    {
        return $this->redirectFactory->create()->setPath('');
    }
}
