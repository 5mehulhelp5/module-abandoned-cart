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

    public function testQuerySupportsQuoteIdentityAndPolicyColumnsInSelectionAndClaims(): void
    {
        $selection = new ReminderQuery(2, 'q');
        $claim = new ReminderQuery(2);
        $this->assertSame(2, $selection->storeId);
        $this->assertSame('q.entity_id', $selection->column('entity_id'));
        $this->assertSame('q.ck_abandoned_reminded_at', $selection->column('ck_abandoned_reminded_at'));
        $this->assertSame('entity_id', $claim->column('entity_id'));
        $this->assertSame('ck_abandoned_reminded_at', $claim->column('ck_abandoned_reminded_at'));
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
                (new ReminderQuery(1))->column($identifier);
                $this->fail('Invalid column was accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('column', $error->getMessage());
            }
        }
    }
}
