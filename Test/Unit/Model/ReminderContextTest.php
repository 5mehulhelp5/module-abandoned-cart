<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use PHPUnit\Framework\TestCase;

class ReminderContextTest extends TestCase
{
    public function testExistingPolicyIgnoresAdditionalStateWithoutChangingItsDecision(): void
    {
        $policy = new OncePerCart();
        foreach ([null, '2026-10-01 12:00:00'] as $lastSent) {
            $base = new ReminderState(42, 1, $lastSent);
            $extended = new ReminderState(42, 1, $lastSent, ['send_count' => $lastSent === null ? 0 : 2,
                'future_campaign_state' => 'example']);
            $this->assertSame($policy->canSend($base), $policy->canSend($extended));
            $this->assertNull($base->getAttribute('send_count'));
            $this->assertSame('example', $extended->getAttribute('future_campaign_state'));
        }
    }

    public function testQuerySuppliesTheCartIdAndOnlySendHistoryColumns(): void
    {
        $query = new ReminderQuery(2, 'c', 'q');
        $this->assertSame(2, $query->storeId);
        $this->assertSame('q.entity_id', $query->quoteId(), 'present in every selection and claim');
        $this->assertSame('c.reminded_at', $query->column('reminded_at'));
        $this->assertSame('c.queued_at', $query->column('queued_at'));
        $this->assertSame('entity_id', (new ReminderQuery(2))->quoteId());
        foreach (['entity_id', 'quote_id', 'updated_at'] as $column) {
            try {
                $query->column($column);
                $this->fail($column . ' is not a send-history column a policy may use.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('quoteId()', $error->getMessage());
            }
        }
    }

    public function testMalformedSqlIdentifiersAreRejectedBeforeBuildingACondition(): void
    {
        foreach (['q.updated_at', 'q; DELETE', 'quote alias'] as $identifier) {
            try {
                new ReminderQuery(1, $identifier);
                $this->fail('Invalid alias was accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('alias', $error->getMessage());
            }
            try {
                new ReminderQuery(1, 'c', $identifier);
                $this->fail('Invalid cart alias was accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('alias', $error->getMessage());
            }
            try {
                (new ReminderQuery(1))->column($identifier);
                $this->fail('Invalid column was accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('column', $error->getMessage());
            }
        }
    }
}
