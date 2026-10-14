<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Signs restore links in the reminder email: HMAC with the store's crypt key over
 * purpose + quote id. Nobody can build a working link by guessing cart ids, and Magento's own cart
 * key (the masked id, which opens the guest cart REST API) never leaves the server. The email is
 * deliberately NOT signed: a link must keep working when the cart's email changes after sending
 * (guest retypes it, customer changes the account email). Consent signs its own withdrawal links.
 */
class LinkSignature
{
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function sign(string $purpose, int $quoteId): string
    {
        $this->assertDeploymentKey();
        return substr($this->encryptor->hash($purpose . '|' . $quoteId), 0, 32);
    }

    public function isValid(string $purpose, int $quoteId, string $signature): bool
    {
        if ($quoteId <= 0 || !preg_match('/^[a-f0-9]{32}$/D', $signature)) {
            return false;
        }
        try {
            return hash_equals($this->sign($purpose, $quoteId), $signature);
        } catch (\LogicException | \RuntimeException) {
            return false;
        }
    }

    private function assertDeploymentKey(): void
    {
        $key = $this->deploymentConfig->get('crypt/key');
        if (!is_string($key) || trim($key) === '') {
            throw new \LogicException('The deployment crypt key is required for cart recovery links.');
        }
    }
}
