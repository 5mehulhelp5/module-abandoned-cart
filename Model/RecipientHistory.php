<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;

/** Short-lived recipient suppression, independent of quote deletion and consent identity. */
class RecipientHistory
{
    private const TABLE = 'ck_abandoned_cart_recipient';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
        private readonly Random $random,
        private readonly Config $config
    ) {
    }

    /** Keyed, purpose-separated hash; no email or deployment key is written to this table. */
    public function hash(string $email): string
    {
        return $this->encryptor->hash('commercekings_abandoned_cart_recipient|' . mb_strtolower(trim($email)));
    }

    public function isRecent(string $email): bool
    {
        $db = $this->resource->getConnection();
        return (bool)$db->fetchOne($db->select()
            ->from($this->resource->getTableName(self::TABLE), 'recipient_hash')
            ->where('recipient_hash = ?', $this->hash($email))
            ->where('reminded_at >= NOW() - INTERVAL ? DAY', $this->config->getRecipientCooldownDays()));
    }

    /** Atomic across carts/workers. The token owns this claim, including any later release. */
    public function claim(string $email): ?string
    {
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->resource->getTableName(self::TABLE));
        $hash = $this->hash($email);
        $token = $this->random->getRandomString(32, '0123456789abcdef');
        $days = $this->config->getRecipientCooldownDays();
        // Assign the token before the timestamp: both conditions must inspect the old timestamp.
        $db->query("INSERT INTO {$table} (recipient_hash, reminded_at, claim_token) VALUES (?, NOW(), ?)"
            . " ON DUPLICATE KEY UPDATE claim_token = IF(reminded_at < NOW() - INTERVAL {$days} DAY, VALUES(claim_token), claim_token),"
            . " reminded_at = IF(reminded_at < NOW() - INTERVAL {$days} DAY, NOW(), reminded_at)", [$hash, $token]);
        $owner = $db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'claim_token')
            ->where('recipient_hash = ?', $hash));
        return is_string($owner) && hash_equals($token, $owner) ? $token : null;
    }

    /** Failed before SMTP handoff. Never remove a replacement worker's claim. */
    public function release(string $email, string $token): void
    {
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE),
            ['recipient_hash = ?' => $this->hash($email), 'claim_token = ?' => $token]);
    }

    public function cleanUp(): int
    {
        return $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE),
            ['reminded_at < NOW() - INTERVAL ? DAY' => $this->config->getRecipientCooldownDays()]);
    }
}
