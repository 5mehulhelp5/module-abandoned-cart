<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\Data\UnsubscribeContext;
use CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface;
use CommerceKings\Consent\Api\WithdrawalLinkInterface;

/** Default: CommerceKings_Consent's signed withdrawal link, its confirmation page and one-click handling. */
class ConsentUnsubscribeLink implements UnsubscribeLinkInterface
{
    public function __construct(
        private readonly WithdrawalLinkInterface $withdrawalLinks
    ) {
    }

    public function getUrl(UnsubscribeContext $context): string
    {
        return $this->withdrawalLinks->getUrl($context->permissionRef);
    }
}
