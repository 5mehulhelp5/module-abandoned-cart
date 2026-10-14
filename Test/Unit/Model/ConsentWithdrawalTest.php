<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\ConsentWithdrawal;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use PHPUnit\Framework\TestCase;

class ConsentWithdrawalTest extends TestCase
{
    use DbTestTrait;

    public function testNoCartDoesNothing(): void
    {
        $this->assertFalse((new ConsentWithdrawal($this->resource()))->execute(0));
        $this->assertSame([], $this->updates);
    }

    public function testClearsOnlyReminderDataAndPreservesMagentoEmail(): void
    {
        $this->assertTrue((new ConsentWithdrawal($this->resource()))->execute(42));
        $this->assertCount(1, $this->updates);
        $update = $this->updates[0];
        $this->assertArrayNotHasKey('customer_email', $update['data']);
        foreach (['ck_abandoned_email', 'ck_abandoned_email_updated_at', 'ck_abandoned_consent_id', 'ck_abandoned_browser_hash'] as $field) {
            $this->assertNull($update['data'][$field]);
        }
        $this->assertSame('updated_at', (string)$update['data']['updated_at']);
        $this->assertSame(42, $update['where']['entity_id = ?']);
        $this->assertSame(1, $update['where']['is_active = ?']);
        $this->assertContains('ck_abandoned_consent_id IS NOT NULL', self::conditions($update['where']));
    }

    public function testForConsentsClearsEveryCartCapturedWithThem(): void
    {
        $this->assertSame(1, (new ConsentWithdrawal($this->resource()))->forConsents(['a', 'b']));
        foreach ($this->updates as $update) {
            $this->assertSame(['a', 'b'], $update['where']['ck_abandoned_consent_id IN (?)']);
            // regression: numeric-key conditions survive the merge (with `+` they were dropped and
            // customer emails were wiped)
            $this->assertContains('ck_abandoned_consent_id IS NOT NULL', self::conditions($update['where']));
        }
    }

    public function testGuestEndpointCannotClearACustomerCartConsent(): void
    {
        (new ConsentWithdrawal($this->resource()))->execute(42, true);
        $this->assertCount(1, $this->updates);
        foreach ($this->updates as $update) {
            $this->assertContains('customer_id IS NULL', self::conditions($update['where']));
        }
    }

    public function testNoConsentIdsDoesNothing(): void
    {
        $this->assertSame(0, (new ConsentWithdrawal($this->resource()))->forConsents([]));
        $this->assertSame([], $this->updates);
    }

    public function testNothingClearedReturnsFalse(): void
    {
        $this->updateResults = [0, 0];
        $this->assertFalse((new ConsentWithdrawal($this->resource()))->execute(42));
    }

    public function testGuestCaptureClearKeepsTheAuthorizedStore(): void
    {
        (new ConsentWithdrawal($this->resource()))->execute(42, true, 3);
        $this->assertCount(1, $this->updates);
        $this->assertSame(3, $this->updates[0]['where']['store_id = ?']);
        $this->assertContains('customer_id IS NULL', self::conditions($this->updates[0]['where']));
    }
}
