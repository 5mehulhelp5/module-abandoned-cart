<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\GuestEmailCaptureInterface;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\QuoteIdMaskFactory;

/**
 * Guest checkout email -> cart, only with a valid consent for purpose "cart_reminder"
 * (CommerceKings_Consent). With consent: the email goes into Magento's own quote.customer_email, with
 * the consent id and this browser's id hash - in one UPDATE that also checks the consent is still valid,
 * so a withdrawal at the same moment can't be undone by this request. Without consent (never given,
 * switched off - the checkout calls again with an empty email - or withdrawn): whatever this module
 * stored on the cart is cleared. Direct UPDATEs - no quote save. Always answers the same way (no probing).
 */
class GuestEmailCapture implements GuestEmailCaptureInterface
{
    public function __construct(
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly ResourceConnection $resource,
        private readonly ConsentInterface $consent,
        private readonly ConsentGuardInterface $guard,
        private readonly BrowserIdentityInterface $browser,
        private readonly ConsentWithdrawal $withdrawal,
        private readonly Config $config
    ) {
    }

    public function capture(string $cartId, string $email): bool
    {
        $quoteId = (int)$this->quoteIdMaskFactory->create()->load($cartId, 'masked_id')->getQuoteId();
        if (!$quoteId) {
            return false;
        }
        $consent = $this->config->isEnabled() ? $this->consent->getCurrent(Config::PURPOSE) : null;
        $email = trim($email);
        if (!$consent) {
            $this->withdrawal->execute($quoteId);
            return false;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return false;
        }
        $db = $this->resource->getConnection();
        $db->update(
            $this->resource->getTableName('quote'),
            [
                'customer_email' => $email,
                'ck_abandoned_consent_id' => $consent->consentId,
                'ck_abandoned_browser_hash' => $this->browser->hash(),
            ],
            [
                'entity_id = ?' => $quoteId,
                'is_active = ?' => 1,
                'customer_id IS NULL',
                $this->guard->validCondition($db->quote($consent->consentId)),
            ]
        );
        return true;
    }
}
