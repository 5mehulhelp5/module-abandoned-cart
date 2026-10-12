<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Observer;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\ConsentWithdrawal;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * commercekings_consent_withdrawn (CommerceKings_Consent - fired after the withdrawal is stored; switched
 * off in the consent source, "reject all", or the unsubscribe link): for "cart_reminder", clear every
 * open cart captured with one of the withdrawn consents - also the abandoned cart of an expired session.
 */
class ClearCartsOnConsentWithdrawn implements ObserverInterface
{
    public function __construct(
        private readonly ConsentWithdrawal $withdrawal
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        if ($event->getData('purpose') !== Config::PURPOSE) {
            return;
        }
        $this->withdrawal->forConsents((array)$event->getData('consent_ids'));
    }
}
