<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Integration;

use CommerceKings\AbandonedCart\Model\LinkSignature;
use CommerceKings\AbandonedCart\Model\RestoreLink;
use CommerceKings\Notification\Api\SubscriberInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The email reminder's restore link through Magento's real URL builder and signature is byte for byte the link the
 * reminder built before reminder types (the same route, query and signature), so links already sent keep working.
 */
class RestoreLinkTest extends TestCase
{
    private \Magento\Framework\ObjectManagerInterface $om;
    private ?int $quoteId = null;

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        try {
            $this->om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
        } catch (\Magento\Framework\Exception\LocalizedException) {
            // already set by an earlier test in this process
        }
        $this->om->get(StoreManagerInterface::class)->setCurrentStore('default');
    }

    protected function tearDown(): void
    {
        if ($this->quoteId !== null) {
            $resource = $this->om->get(ResourceConnection::class);
            $resource->getConnection()->delete($resource->getTableName('quote'), ['entity_id = ?' => $this->quoteId]);
        }
    }

    public function testTheEmailLinkIsUnchanged(): void
    {
        $store = $this->om->get(StoreManagerInterface::class)->getDefaultStoreView();
        $quote = $this->om->get(QuoteFactory::class)->create();
        $quote->setStore($store)->setIsActive(true)->setCustomerIsGuest(true);
        $this->om->get(CartRepositoryInterface::class)->save($quote);
        $this->quoteId = (int)$quote->getId();
        $subscriber = $this->createStub(SubscriberInterface::class);
        $subscriber->method('identifiesVisitor')->willReturn(true);
        $url = $this->om->create(UrlInterface::class);
        $signature = $this->om->get(LinkSignature::class);
        // the reminder's link as it was built before (ReminderData::restoreUrl at 4772e2d)
        $url->setScope($quote->getStoreId());
        $before = $url->getUrl('cartreminder/cart/restore', [
            '_query' => ['q' => $this->quoteId, 't' => $signature->sign('restore', $this->quoteId)],
            '_nosid' => true,
        ]);
        $now = (new RestoreLink($this->om->create(UrlInterface::class), $signature, $subscriber))
            ->getUrl($quote, 'cart_reminder');
        $this->assertSame($before, $now);
        $this->assertMatchesRegularExpression('#/cartreminder/cart/restore/?\?q=' . $this->quoteId . '&t=[0-9a-f]+$#', $now);
    }
}
