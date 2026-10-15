<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path) => $values[$path] ?? null);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => (bool)($values[$path] ?? false));
        return new Config($scope);
    }

    public function testValues(): void
    {
        $p = 'commercekings_abandoned_cart/general/';
        $config = $this->config([
            $p . 'enabled' => '1', $p . 'delay_minutes' => '180', $p . 'recipient_cooldown_days' => '7',
            $p . 'template' => 'tpl', $p . 'identity' => 'custom1',
        ]);
        $this->assertTrue($config->isEnabled());
        $this->assertSame(180, $config->getDelayMinutes());
        $this->assertSame(7, $config->getRecipientCooldownDays());
        $this->assertSame('tpl', $config->getTemplate(1));
        $this->assertSame('custom1', $config->getIdentity(1));
    }

    public function testNumbersNeverBelowOne(): void
    {
        $config = $this->config([]);
        $this->assertFalse($config->isEnabled());
        $this->assertSame(1, $config->getDelayMinutes());
        $this->assertSame(1, $config->getRecipientCooldownDays());
    }
}
