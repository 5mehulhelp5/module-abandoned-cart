<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;

/**
 * Who got a reminder in the last Config::RECIPIENT_COOLDOWN_DAYS - one row per address, stored as a
 * keyed hash (HMAC-SHA256 of the lowercased address, key derived from the store's crypt key), never
 * the address itself. A plain hash of an email can be matched against any list of addresses; this one
 * only with the key from env.php, so a copy of the database alone does not reveal who was reminded.
 * Still personal data (the shop holds the key) - erased with the address, see hash(). The key never
 * goes into SQL (query logs), so every hash is computed here, not in MySQL. Kept apart from the carts on purpose: Magento
 * deletes carts (guest cart merged into the customer's on login, old-cart cleanup), and a cart's
 * email can be changed or cleared (opt-out) - none of that may reset the cooldown.
 * Rows older than the cooldown are deleted by the daily cleanup cron.
 */
class RecipientHistory
{
    private const TABLE = 'ck_abandoned_cart_recipient';

    /** Derivation label: the crypt key itself is never used directly (LinkSignature uses it too). */
    private const KEY_LABEL = 'commercekings_abandoned_cart_recipient';

    private ?string $key = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /** The stored form of an address. A new crypt key (key rotation) only resets the cooldowns. */
    public function hash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), $this->key());
    }

    /**
     * Which of these addresses got a reminder within the cooldown - one query.
     *
     * @param string[] $emails
     * @return string[] the recent ones, as given
     */
    public function recent(array $emails): array
    {
        $hashes = [];
        foreach ($emails as $email) {
            $hashes[$this->hash((string)$email)][] = (string)$email;
        }
        if (!$hashes) {
            return [];
        }
        $db = $this->resource->getConnection();
        $found = $db->fetchCol(
            $db->select()->from($this->resource->getTableName(self::TABLE), 'recipient_hash')
                ->where('recipient_hash IN (?)', array_keys($hashes))
                ->where('reminded_at >= NOW() - INTERVAL ? DAY', Config::RECIPIENT_COOLDOWN_DAYS)
        );
        $recent = [];
        foreach ($found as $hash) {
            array_push($recent, ...($hashes[$hash] ?? []));
        }
        return $recent;
    }

    public function isRecent(string $email): bool
    {
        $db = $this->resource->getConnection();
        return (bool)$db->fetchOne(
            $db->select()->from($this->resource->getTableName(self::TABLE), 'recipient_hash')
                ->where('recipient_hash = ?', $this->hash($email))
                ->where('reminded_at >= NOW() - INTERVAL ? DAY', Config::RECIPIENT_COOLDOWN_DAYS)
        );
    }

    /**
     * Atomic: records "reminded now" unless the address already got one within the cooldown.
     * True = this caller may send. Two consumers sending to one address at once: only one wins
     * (affected rows: 1 = inserted, 2 = expired row refreshed, 0 = still in cooldown).
     */
    public function claim(string $email): bool
    {
        $table = $this->resource->getTableName(self::TABLE);
        $days = (int)Config::RECIPIENT_COOLDOWN_DAYS;
        return $this->resource->getConnection()->query(
            "INSERT INTO {$table} (recipient_hash, reminded_at) VALUES (?, NOW())"
            . " ON DUPLICATE KEY UPDATE reminded_at = IF(reminded_at < NOW() - INTERVAL {$days} DAY, NOW(), reminded_at)",
            [$this->hash($email)]
        )->rowCount() > 0;
    }

    /** Sending failed - forget the claim so the next run can try again. */
    public function release(string $email): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['recipient_hash = ?' => $this->hash($email)]
        );
    }

    public function cleanUp(): int
    {
        return $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['reminded_at < NOW() - INTERVAL ? DAY' => Config::RECIPIENT_COOLDOWN_DAYS]
        );
    }

    private function key(): string
    {
        if ($this->key === null) {
            $cryptKey = (string)$this->deploymentConfig->get('crypt/key');
            if ($cryptKey === '') {
                throw new \LogicException('CommerceKings_AbandonedCart: no crypt key in app/etc/env.php.');
            }
            $this->key = hash_hmac('sha256', self::KEY_LABEL, $cryptKey, true);
        }
        return $this->key;
    }
}
