<?php
declare(strict_types=1);

use CommerceKings\AbandonedCart\Cron\FindAbandonedCarts;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\Notification\Model\Config as NotificationConfig;
use CommerceKings\Notification\Model\Mailer;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Quote\Api\CartRepositoryInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\NullLogger;

require (getenv('MAGENTO_ROOT') ?: getcwd()) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(State::class)->setAreaCode('frontend');
$stores = $om->get(StoreManagerInterface::class);
$stores->reinitStores();
$stores->setCurrentStore('default');
$storeId = (int)$argv[2];
$websiteId = (int)$stores->getStore($storeId)->getWebsiteId();
$ids = array_map('intval', explode(',', $argv[3]));
$mode = $argv[1];
$config = new class($storeId) extends Config {
    public function __construct(private int $storeId) {}
    public function isEnabled(?int $storeId = null): bool { return $storeId === $this->storeId; }
    public function getDelayMinutes(?int $storeId = null): int { return 1; }
    public function getMaxPending(): int { return 2000; }
    public function getMaxPerHour(): int { return 0; }
    public function getTemplate(int $storeId): string { return 'fixture'; }
    public function getIdentity(int $storeId): string { return 'general'; }
};
$guard = new class implements ConsentGuardInterface {
    public function validCondition(string $consentIdColumn): string { return '1 = 1'; }
};
$resource = $om->get(ResourceConnection::class);
$lifetime = new QuoteLifetime($om->get(ScopeConfigInterface::class));
$recipients = $om->get(ReminderEmailResolverInterface::class);
$published = [];
$sent = 0;
$queue = new class($published, $resource) implements QueueInterface {
    public function __construct(private array &$published, private ResourceConnection $resource) {}
    // report quote ids: the finder publishes subscription ids
    public function publish(string $type, string $reference): void
    {
        $db = $this->resource->getConnection();
        $this->published[] = (int)$db->fetchOne($db->select()->from($this->resource->getTableName('ck_notification_subscription'),
            'reference_id')->where('subscription_id = ?', (int)$reference));
        usleep(10000);
    }
};
$consents = new class($websiteId) implements ConsentInterface {
    public function __construct(private int $websiteId) {}
    public function getCurrent(string $purpose): ?Consent { return $this->get('fixture-consent'); }
    public function isValid(string $consentId): bool { return $consentId === 'fixture-consent'; }
    public function withdraw(string $consentId, string $via): array { return []; }
    public function get(string $consentId): ?Consent {
        return new Consent('fixture-consent', 'cart_reminder', $this->websiteId, 'fixture', 'choice',
            '2026-01-01 00:00:00', '1', '1', '2026-01-01 00:00:00', '2027-01-01 00:00:00');
    }
};
$onSend = static function () use (&$sent): void { $sent++; usleep(20000); };
$builder = new class($onSend, $mode) extends TransportBuilder {
    public function __construct(private Closure $onSend, private string $mode) {}
    public function setTemplateIdentifier($templateIdentifier): self { return $this; }
    public function setTemplateOptions($templateOptions): self { return $this; }
    public function setTemplateVars($templateVars): self { return $this; }
    public function setFromByScope($from, $scopeId = null): self { return $this; }
    public function addTo($address, $name = ''): self { return $this; }
    public function getTransport(): TransportInterface {
        if ($this->mode === 'interrupt') {
            // Simulate a worker disappearing after its committed claim, before SMTP.
            echo json_encode(['interrupted' => true, 'sent' => 0]), PHP_EOL;
            exit(0);
        }
        return new class($this->onSend) implements TransportInterface {
            public function __construct(private Closure $onSend) {}
            public function sendMessage(): void { ($this->onSend)(); }
            public function getMessage() { return null; }
        };
    }
};
$data = new class extends ReminderData {
    public function __construct() {}
    public function build(Quote $quote, string $consentId): array { return ['customer_name' => '']; }
};
$emulation = new class extends Emulation {
    public function __construct() {}
    public function startEnvironmentEmulation($storeId, $area = 'frontend', $force = false) {}
    public function stopEnvironmentEmulation() {}
};
$barrier = $argv[4];
file_put_contents($barrier . '.' . $argv[5] . '.ready', 'ready');
$deadline = microtime(true) + 30;
while (!is_file($barrier . '.go')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Reminder worker barrier timed out.'); }
    usleep(10000);
}
if ($mode === 'finder') {
    (new FindAbandonedCarts($config, $resource, $guard, $queue, new OncePerCart(), $lifetime,
        $stores, new NullLogger(), $recipients, $om->get(CooldownInterface::class), $om->get(\CommerceKings\AbandonedCart\Model\CartTable::class),
        $om->get(\Magento\Framework\Lock\LockManagerInterface::class)))->execute();
} else {
    $mailer = new Mailer($builder, $emulation, $om->get(CooldownInterface::class), $om->get(NotificationConfig::class));
    $consumer = new CartReminder($resource, $om->get(CartRepositoryInterfaceFactory::class)->create(), $data,
        $mailer, $config, $consents, $guard, new OncePerCart(), new NullLogger(),
        $om->get(QuoteScope::class), $lifetime, $recipients, $om->get(\CommerceKings\Consent\Model\Config::class),
        $om->get(\CommerceKings\AbandonedCart\Model\CartTable::class));
    $db = $resource->getConnection();
    foreach ($ids as $id) {
        // the queue message carries the cart's subscription id
        $consumer->process((string)$db->fetchOne($db->select()->from($resource->getTableName('ck_notification_subscription'),
            'subscription_id')->where('type = ?', 'cart_reminder')->where('reference_id = ?', $id)));
    }
}
echo json_encode(['published' => $published, 'sent' => $sent]), PHP_EOL;
