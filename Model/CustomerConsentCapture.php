<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Logged-in customers: the same rule as guests, at the equivalent moment. A guest consents to the
 * reminder by typing the email in checkout; a customer's email comes from the account, so the
 * consent is stored when they open checkout (or switch the consent on while there) - if
 * CommerceKings_Consent has a valid one for purpose "cart_reminder". Consent id and browser id as for
 * guests, with the same validity check inside the UPDATE. A change stamps captured_at, which restarts the
 * reminder delay (as a cart change does); re-opening checkout with the same consent changes nothing.
 */
class CustomerConsentCapture
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ConsentInterface $consent,
        private readonly ConsentGuardInterface $guard,
        private readonly BrowserIdentityInterface $browser,
        private readonly Config $config,
        private readonly QuoteScope $scope,
        private readonly CartTable $carts
    ) {
    }

    public function execute(int $quoteId, int $customerId): bool
    {
        if (!$quoteId || !$customerId) {
            return false;
        }
        $quote = $this->scope->find($quoteId, $customerId);
        if (!$quote || !$this->config->isEnabled((int)$quote['store_id'])) {
            return false;
        }
        $consent = $this->consent->getCurrent(Config::PURPOSE);
        if (!$consent || !$this->scope->matches($consent, (int)$quote['store_id'])) {
            return false;
        }
        $browserHash = $this->browser->hash();
        $db = $this->resource->getConnection();
        $where = [
            'q.entity_id = ?' => $quoteId,
            'q.store_id = ?' => (int)$quote['store_id'],
            'q.customer_id = ?' => $customerId,
            'q.is_active = ?' => 1,
            $this->guard->validCondition($db->quote($consent->consentId)),
        ];
        $this->carts->ensure($quoteId);
        $updated = $db->update(
            $this->carts->joined(),
            ['c.consent_id' => $consent->consentId, 'c.browser_hash' => $browserHash, 'c.captured_at' => new Expression('NOW()')],
            array_merge($where, [
                // only when it changes - re-opening checkout must not restart the reminder delay
                '(c.consent_id IS NULL OR c.browser_hash IS NULL OR '
                    . $db->quoteInto('c.consent_id <> ?', $consent->consentId) . ' OR '
                    . $db->quoteInto('c.browser_hash <> ?', $browserHash) . ')',
            ])
        );
        if ($updated) {
            return true;
        }
        // An identical retry is stored; a refused write or a withdrawal race is not.
        $select = $this->carts->select(['c.quote_id']);
        foreach ($where as $condition => $value) {
            is_int($condition) ? $select->where($value) : $select->where($condition, $value);
        }
        return (bool)$db->fetchOne($select->where('c.consent_id = ?', $consent->consentId)
            ->where('c.browser_hash = ?', $browserHash));
    }
}
