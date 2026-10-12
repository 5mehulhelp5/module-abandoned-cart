<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Consent\Api\WithdrawalLinkInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Compatibility bridge for signed quote-based links in emails sent before consent-owned links.
 */
class OptOut
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LinkSignature $signature,
        private readonly WithdrawalLinkInterface $links
    ) {
    }

    public function getWithdrawalUrl(int $quoteId, string $token): ?string
    {
        if (!$this->signature->isValid('optout', $quoteId, $token)) {
            return null;
        }
        $db = $this->resource->getConnection();
        $consentId = (string)$db->fetchOne(
            $db->select()->from($this->resource->getTableName('quote'), 'ck_abandoned_consent_id')
                ->where('entity_id = ?', $quoteId)
        );
        if ($consentId === '') {
            return null;
        }
        try {
            return $this->links->getUrl($consentId);
        } catch (LocalizedException) {
            return null;
        }
    }
}
