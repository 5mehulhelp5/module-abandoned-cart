<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Logged-in customers: the same rule as guests, at the equivalent moment. A guest consents to the
 * reminder by typing the email in checkout; a customer's email comes from the account, so the
 * consent is stored when they open checkout (or switch the consent on while there) - if
 * CommerceKings_Consent has a valid one for purpose "cart_reminder". Consent id and browser id as for
 * guests, with the same validity check inside the UPDATE.
 */
class CustomerConsentCapture
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ConsentInterface $consent,
        private readonly ConsentGuardInterface $guard,
        private readonly BrowserIdentityInterface $browser,
        private readonly Config $config
    ) {
    }

    public function execute(int $quoteId, int $customerId): bool
    {
        if (!$quoteId || !$customerId || !$this->config->isEnabled()) {
            return false;
        }
        $consent = $this->consent->getCurrent(Config::PURPOSE);
        if (!$consent) {
            return false;
        }
        $browserHash = $this->browser->hash();
        $db = $this->resource->getConnection();
        $db->update(
            $this->resource->getTableName('quote'),
            ['ck_abandoned_consent_id' => $consent->consentId, 'ck_abandoned_browser_hash' => $browserHash],
            [
                'entity_id = ?' => $quoteId,
                'customer_id = ?' => $customerId,
                'is_active = ?' => 1,
                // only when it changes - re-opening checkout must not restart the reminder delay
                '(ck_abandoned_consent_id IS NULL OR ck_abandoned_browser_hash IS NULL OR '
                    . $db->quoteInto('ck_abandoned_consent_id <> ?', $consent->consentId) . ' OR '
                    . $db->quoteInto('ck_abandoned_browser_hash <> ?', $browserHash) . ')',
                $this->guard->validCondition($db->quote($consent->consentId)),
            ]
        );
        return true;
    }
}
