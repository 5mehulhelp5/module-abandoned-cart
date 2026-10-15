<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\AbandonedCart\Api\GuestEmailCaptureInterface;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Notification\Api\ContactRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResource;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Guest checkout email -> cart, only with a valid consent for purpose "cart_reminder"
 * (CommerceKings_Consent). With consent: the email becomes a CommerceKings_Notification contact and the
 * cart's state (ck_abandoned_cart) gets its contact, the consent id and this browser's id hash - in one
 * UPDATE (joined to the quote) that also checks the consent is still valid,
 * so a withdrawal at the same moment can't be undone by this request. Without consent (never given,
 * switched off - the checkout calls again with an empty email - or withdrawn): whatever this module
 * stored on the guest cart is cleared. Direct UPDATEs - no quote save. The response acknowledges
 * whether the requested email and consent were stored, including an identical successful retry.
 * Email changes have their own timestamp (captured_at); Magento's quote row is not written at all.
 */
class GuestEmailCapture implements GuestEmailCaptureInterface
{
    public function __construct(
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly QuoteIdMaskResource $quoteIdMaskResource,
        private readonly ResourceConnection $resource,
        private readonly ConsentInterface $consent,
        private readonly ConsentGuardInterface $guard,
        private readonly BrowserIdentityInterface $browser,
        private readonly ConsentWithdrawal $withdrawal,
        private readonly Config $config,
        private readonly QuoteScope $scope,
        private readonly CartTable $carts,
        private readonly ContactRepositoryInterface $contacts,
        private readonly StoreManagerInterface $stores
    ) {
    }

    public function capture(string $cartId, mixed $email): bool
    {
        $mask = $this->quoteIdMaskFactory->create();
        $this->quoteIdMaskResource->load($mask, $cartId, 'masked_id');
        $quoteId = (int)$mask->getQuoteId();
        if (!$quoteId) {
            return false;
        }
        $quote = $this->scope->find($quoteId);
        if (!$quote) {
            return false;
        }
        $storeId = (int)$quote['store_id'];
        $consent = $this->config->isEnabled($storeId) ? $this->consent->getCurrent(Config::PURPOSE) : null;
        if (!$consent || $email === '') {
            $this->withdrawal->execute($quoteId, true, $storeId);
            return false;
        }
        if (!$this->scope->matches($consent, $storeId)) {
            return false;
        }
        $db = $this->resource->getConnection();
        $contactId = $this->contacts->save((int)$this->stores->getStore($storeId)->getWebsiteId(), (string)$email);
        $where = [
            'q.entity_id = ?' => $quoteId,
            'q.store_id = ?' => $storeId,
            'q.is_active = ?' => 1,
            'q.customer_id IS NULL',
            $this->guard->validCondition($db->quote($consent->consentId)),
        ];
        $browserHash = $this->browser->hash();
        $this->carts->ensure($quoteId);
        $updated = $db->update(
            $this->carts->joined(),
            [
                // MySQL evaluates assignments in order: compare the old contact before replacing it.
                'c.captured_at' => new Expression((string)$db->getCheckSql(
                    'c.contact_id <=> ' . (int)$contactId . ' AND c.captured_at IS NOT NULL',
                    'c.captured_at',
                    'NOW()'
                )),
                'c.contact_id' => $contactId,
                'c.consent_id' => $consent->consentId,
                'c.browser_hash' => $browserHash,
            ],
            $where
        );
        if ($updated) {
            return true;
        }
        // Retrying an acknowledged-but-lost response may write identical values (zero rows changed).
        $select = $this->carts->select(['c.quote_id']);
        foreach ($where as $condition => $value) {
            is_int($condition) ? $select->where($value) : $select->where($condition, $value);
        }
        return (bool)$db->fetchOne($select->where('c.contact_id = ?', $contactId)
            ->where('c.consent_id = ?', $consent->consentId)
            ->where('c.browser_hash = ?', $browserHash));
    }
}
