<?php
declare(strict_types=1);

use CommerceKings\AbandonedCart\Cron\FindAbandonedCarts;
use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\ReminderData;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\AbandonedCart\Api\ReminderEmailResolverInterface;
use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Model\Config as NotificationConfig;
use CommerceKings\Notification\Model\OneClickHeaders;
use CommerceKings\Notification\Model\Provider\MagentoEmail;
use CommerceKings\Notification\Model\ProviderPool;
use CommerceKings\Notification\Model\Sender;
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
$ids = array_map('intval', explode(',', $argv[3]));
$mode = $argv[1];
$config = new class($storeId) extends Config {
    public function __construct(private int $storeId) {}
    public function isEnabled(?int $storeId = null): bool { return $storeId === $this->storeId; }
    public function getDelayMinutes(?int $storeId = null): int { return 1; }
    public function getMaxPerHour(): int { return 0; }
    public function getTemplate(int $storeId): string { return 'fixture'; }
    public function getIdentity(int $storeId): string { return 'general'; }
};
// Notification's permission: the fixture subscriptions (permission_ref 'fixture-consent') stay allowed
$guard = new class implements SubscriptionPermissionInterface {
    public function check(SubscriptionTypeInterface $type, Target $target, ?string $recipient = null): Permission { return Permission::refused(); }
    public function validCondition(string $referenceColumn): string { return '1 = 1'; }
    public function isValidFor(string $reference, string $purpose, int $storeId): bool { return $reference === 'fixture-consent'; }
    public function isOptOut(): bool { return false; }
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
    public function build(Quote $quote, string $permissionRef, array $context = []): array { return ['customer_name' => '']; }
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
// the built-in email reminder over the fixture config, recipients and data
$email = new \CommerceKings\AbandonedCart\Model\Reminder\EmailReminder($config, $recipients, $data, new OncePerCart(),
    $om->get(NotificationConfig::class));
if ($mode === 'finder') {
    (new FindAbandonedCarts($resource, $guard, $queue, $lifetime, $stores, new NullLogger(),
        $om->get(CooldownInterface::class), $om->get(\CommerceKings\AbandonedCart\Model\CartTable::class),
        $om->get(\CommerceKings\Notification\Api\QuietHoursInterface::class),
        new \CommerceKings\AbandonedCart\Model\ReminderTypePool(['cart_reminder' => $email])))->execute();
} else {
    $mailer = new Sender(new ProviderPool($om->get(NotificationConfig::class), [new MagentoEmail($builder, new OneClickHeaders(),
        new \Psr\Log\NullLogger())]), $emulation, $om->get(CooldownInterface::class), $om->get(NotificationConfig::class),
        new \Psr\Log\NullLogger(), new \CommerceKings\Notification\Model\RecipientNormalizerPool(),
        $om->get(\CommerceKings\Notification\Api\StopListInterface::class),
        $om->get(\CommerceKings\Notification\Api\QuietHoursInterface::class));
    $consumer = new CartReminder($resource, $om->get(CartRepositoryInterfaceFactory::class), $mailer, $guard,
        new NullLogger(), $lifetime, $om->get(\CommerceKings\AbandonedCart\Model\CartTable::class),
        $om->get(\Magento\Framework\Lock\LockManagerInterface::class),
        $om->get(\CommerceKings\Notification\Api\SubscriptionRemovalInterface::class),
        $om->get(\CommerceKings\Notification\Api\QuietHoursInterface::class), $email);
    $db = $resource->getConnection();
    foreach ($ids as $id) {
        // the queue message carries the cart's subscription id
        $consumer->process((string)$db->fetchOne($db->select()->from($resource->getTableName('ck_notification_subscription'),
            'subscription_id')->where('type = ?', 'cart_reminder')->where('reference_id = ?', $id)));
    }
}
echo json_encode(['published' => $published, 'sent' => $sent]), PHP_EOL;
