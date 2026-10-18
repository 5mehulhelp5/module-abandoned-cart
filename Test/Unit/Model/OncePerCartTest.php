<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use PHPUnit\Framework\TestCase;

class OncePerCartTest extends TestCase
{
    public function testOnlyAnUnremindedCartCanSendRegardlessOfStore(): void
    {
        $policy = new OncePerCart();
        foreach ([1, 2] as $storeId) {
            $this->assertTrue($policy->canSend(new ReminderState(42, $storeId, null)));
            $this->assertFalse($policy->canSend(new ReminderState(42, $storeId, '2020-01-01 00:00:00')));
            $this->assertFalse($policy->canSend(new ReminderState(42, $storeId, '')));
        }
        $this->assertSame('c.sent_at IS NULL', $policy->dueCondition(new ReminderQuery(1, 'c', 'q')));
    }
}
