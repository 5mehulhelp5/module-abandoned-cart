<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Test\Unit\Fixture\CompatibleReminderPolicy;
use PHPUnit\Framework\TestCase;

class ReminderPolicyCompatibilityTest extends TestCase
{
    public function testThirdPartyContractAndNamedContextArgumentsRemainUsable(): void
    {
        // Frozen public signatures, including names used by PHP named arguments and return types.
        $signatures = [
            'getDescription' => [[], 'string'],
            'canSend' => [[['state', ReminderState::class]], 'bool'],
            'dueCondition' => [[['query', ReminderQuery::class]], 'string'],
        ];
        foreach ($signatures as $method => [$parameters, $returnType]) {
            $reflection = new \ReflectionMethod(ReminderPolicyInterface::class, $method);
            $actual = array_map(fn (\ReflectionParameter $parameter) =>
                [$parameter->getName(), (string)$parameter->getType()], $reflection->getParameters());
            $this->assertSame($parameters, $actual, $method . ' public parameters changed');
            $this->assertSame($returnType, (string)$reflection->getReturnType(), $method . ' public return type changed');
        }
        $policy = new CompatibleReminderPolicy();
        $state = new ReminderState(quoteId: 42, storeId: 1, lastRemindedAt: null,
            attributes: ['future_attribute' => 'new state']);
        $query = new ReminderQuery(storeId: 1, tableAlias: 'c', quoteAlias: 'q');
        $this->assertSame(42, $state->quoteId);
        $this->assertSame(1, $state->storeId);
        $this->assertNull($state->lastRemindedAt);
        $this->assertSame('new state', $state->getAttribute('future_attribute'));
        $this->assertSame('Compatibility fixture', $policy->getDescription());
        $this->assertFalse($policy->canSend($state));
        $this->assertSame('q.entity_id < 0 AND c.reminded_at IS NULL', $policy->dueCondition($query));
        // ReminderPolicyTest runs this fixture through the real finder and claim SQL.
    }
}
