<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\ReminderTypePool;
use CommerceKings\AbandonedCart\Test\Unit\Fixture\TestReminderType;
use PHPUnit\Framework\TestCase;

class ReminderTypePoolTest extends TestCase
{
    public function testTypesInTheOrderRegistered(): void
    {
        $sms = new TestReminderType('cart_reminder_sms');
        $whatsapp = new TestReminderType('cart_reminder_whatsapp', 'whatsapp');
        $pool = new ReminderTypePool(['cart_reminder_sms' => $sms, 'cart_reminder_whatsapp' => $whatsapp]);
        $this->assertSame(['cart_reminder_sms' => $sms, 'cart_reminder_whatsapp' => $whatsapp], $pool->getAll());
        $this->assertSame($whatsapp, $pool->get('cart_reminder_whatsapp'));
        $this->assertNull($pool->get('cart_reminder_viber'));
    }

    public function testAnItemMustBeRegisteredUnderItsOwnCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReminderTypePool(['cart_reminder_sms' => new TestReminderType('cart_reminder_whatsapp')]);
    }

    public function testAnItemMustBeAReminderType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReminderTypePool(['cart_reminder_sms' => new \stdClass()]);
    }
}
