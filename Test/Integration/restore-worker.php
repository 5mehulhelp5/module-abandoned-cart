<?php
declare(strict_types=1);

use CommerceKings\AbandonedCart\Model\CartRestore;
use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\RestoreAccess;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterfaceFactory;
use Magento\Store\Model\StoreManagerInterface;

require (getenv('MAGENTO_ROOT') ?: getcwd()) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(State::class)->setAreaCode('frontend');
$stores = $om->get(StoreManagerInterface::class);
$stores->setCurrentStore('default');
$browser = new class implements BrowserIdentityInterface {
    public function hash(): string { return str_repeat('a', 64); }
    public function ensure(): string { return $this->hash(); }
};
$factory = $om->get(CartRepositoryInterfaceFactory::class);
// Preload before either operation starts: the production service must discard this stale view.
$current = $factory->create()->get((int)$argv[2]);
$barrier = $argv[3];
file_put_contents($barrier . '.' . $argv[4] . '.ready', 'ready');
$deadline = microtime(true) + 10;
while (!is_file($barrier . '.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrent restore barrier timed out.');
    }
    usleep(10000);
}
$signature = $om->get(LinkSignature::class);
$service = new CartRestore($om->get(ResourceConnection::class), $factory, new RestoreAccess($signature, $browser, $stores, $om->get(\CommerceKings\AbandonedCart\Model\QuoteLifetime::class),
    $om->get(\CommerceKings\AbandonedCart\Model\CartTable::class)));
try {
    $service->execute((int)$argv[1], $current, $signature->sign('restore', (int)$argv[1]), 0);
    echo json_encode(['restored' => true]), PHP_EOL;
} catch (LocalizedException) {
    echo json_encode(['restored' => false]), PHP_EOL;
}
