<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;

/**
 * Unsubscribe link in the reminder: /cartreminder/optout?q={quote id}&t={signature}.
 * The signature (LinkSignature) means nobody can opt out someone else by guessing ids. Opting out
 * withdraws the consent the cart was captured with in CommerceKings_Consent (with every other active
 * reminder consent of that browser) - never the email address. CommerceKings_Consent then fires
 * commercekings_consent_withdrawn and the carts are cleared (Observer\ClearCartsOnConsentWithdrawn).
 * That browser's old choice never counts again (link opened on a phone, the "yes" is on the laptop);
 * a new choice in the banner does.
 */
class OptOut
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LinkSignature $signature,
        private readonly ConsentInterface $consent,
        private readonly ConsentWithdrawal $withdrawal,
        private readonly UrlInterface $url
    ) {
    }

    public function url(int $quoteId): string
    {
        return $this->url->getUrl('cartreminder/optout', [
            '_query' => ['q' => $quoteId, 't' => $this->signature->sign('optout', $quoteId)],
            '_nosid' => true,
        ]);
    }

    public function isValid(int $quoteId, string $token): bool
    {
        return $this->signature->isValid('optout', $quoteId, $token);
    }

    /** Withdraws the consent this cart was captured with; false if it has none (already opted out). */
    public function withdraw(int $quoteId): bool
    {
        $db = $this->resource->getConnection();
        $consentId = (string)$db->fetchOne(
            $db->select()->from($this->resource->getTableName('quote'), 'ck_abandoned_consent_id')
                ->where('entity_id = ?', $quoteId)
        );
        if ($consentId === '') {
            return false;
        }
        $this->consent->withdraw($consentId, 'email');
        // the event already cleared them; also when this consent was withdrawn before and a cart kept it
        $this->withdrawal->forConsents([$consentId]);
        return true;
    }
}
