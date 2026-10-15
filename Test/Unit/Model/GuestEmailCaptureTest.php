<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCart\Test\Unit\Model;

use CommerceKings\AbandonedCart\Model\Config;
use CommerceKings\AbandonedCart\Model\ConsentWithdrawal;
use CommerceKings\AbandonedCart\Model\GuestEmailCapture;
use CommerceKings\AbandonedCart\Model\QuoteScope;
use CommerceKings\AbandonedCart\Test\Unit\DbTestTrait;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResource;
use PHPUnit\Framework\TestCase;

// Shared fixtures use scenario-specific expectations; other outcomes are asserted from captured data.
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class GuestEmailCaptureTest extends TestCase
{
    use DbTestTrait;

    private ConsentWithdrawal $withdrawal;
    private ?string $askedPurpose = null;
    private array $maskLoads = [];
    private ?array $scopedQuote = ['store_id' => 3];
    private bool $scopeMatches = true;
    private array $savedContacts = [];

    private function capture(int $quoteId = 42, bool $consent = true, bool $enabled = true): GuestEmailCapture
    {
        $mask = $this->mockWith(QuoteIdMask::class, ['getQuoteId' => $quoteId]);
        $maskFactory = $this->createStub(QuoteIdMaskFactory::class);
        $maskFactory->method('create')->willReturn($mask);
        $maskResource = $this->createStub(QuoteIdMaskResource::class);
        $maskResource->method('load')->willReturnCallback(function ($model, $value, $field) use ($mask, $maskResource) {
            $this->assertSame($mask, $model);
            $this->maskLoads[] = [$value, $field];
            return $maskResource;
        });
        $consentApi = $this->createStub(ConsentInterface::class);
        $consentApi->method('getCurrent')->willReturnCallback(function ($purpose) use ($consent) {
            $this->askedPurpose = $purpose;
            // CommerceKings_Consent returns only a valid consent - withdrawn / old revision = null too
            return $consent ? self::consent('cid-1') : null;
        });
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturn('browser-hash');
        $config = $this->createMock(Config::class);
        $config->expects($this->any())->method('isEnabled')->with(3)->willReturn($enabled);
        $scope = $this->createStub(QuoteScope::class);
        $scope->method('find')->willReturnCallback(fn () => $this->scopedQuote);
        $scope->method('matches')->willReturnCallback(fn () => $this->scopeMatches);
        $this->withdrawal = $this->createMock(ConsentWithdrawal::class);
        $contacts = $this->createStub(\CommerceKings\Notification\Api\ContactRepositoryInterface::class);
        $contacts->method('save')->willReturnCallback(function ($websiteId, $email) {
            $this->savedContacts[] = [$websiteId, $email];
            return 7;
        });
        $store = $this->createStub(\Magento\Store\Api\Data\StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $stores = $this->createStub(\Magento\Store\Model\StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return new GuestEmailCapture($maskFactory, $maskResource, $this->resource(), $consentApi, $this->guard(), $browser,
            $this->withdrawal, $config, $scope, $this->carts(), $contacts, $stores);
    }

    public function testUnknownCartKey(): void
    {
        $this->assertFalse($this->capture(0)->capture('nope', 'a@test.com'));
        $this->assertSame([['nope', 'masked_id']], $this->maskLoads);
        $this->assertSame([], $this->updates);
    }

    public function testStoresEmailWithTheConsentIdForTheCartReminderPurpose(): void
    {
        $this->assertTrue($this->capture()->capture('masked', 'a@test.com'));
        $this->assertSame([['masked', 'masked_id']], $this->maskLoads);
        $this->assertSame('cart_reminder', $this->askedPurpose);
        // the email is a Notification contact of the cart's website; the cart's state points to it
        $this->assertSame([[1, 'a@test.com']], $this->savedContacts);
        $this->assertStringContainsString('INSERT IGNORE INTO ck_abandoned_cart (quote_id)', $this->queries[0][0]);
        $this->assertSame([42], $this->queries[0][1]);
        $this->assertSame(self::joined(), $this->updates[0]['table']);
        $data = $this->updates[0]['data'];
        $this->assertSame(7, $data['c.contact_id']);
        $this->assertSame('cid-1', $data['c.consent_id']);
        $this->assertSame('browser-hash', $data['c.browser_hash']);
        $this->assertStringContainsString('c.contact_id <=> 7', (string)$data['c.captured_at']);
        $this->assertSame(['c.captured_at', 'c.contact_id', 'c.consent_id', 'c.browser_hash'], array_keys($data),
            'Magento\'s quote row is not written');
        $where = $this->updates[0]['where'];
        $this->assertSame(42, $where['q.entity_id = ?']);
        $this->assertSame(1, $where['q.is_active = ?']);
        $this->assertContains('q.customer_id IS NULL', self::conditions($where)); // guests only
    }

    public function testCodexFinding2TheConsentIsCheckedInsideTheSameUpdate(): void
    {
        // a withdrawal stored a moment earlier makes this UPDATE change nothing - it can't be undone
        $this->capture()->capture('masked', 'a@test.com');
        $this->assertContains("VALID('cid-1')", self::conditions($this->updates[0]['where']));
    }

    /** @dataProvider noConsentCases */
    #[\PHPUnit\Framework\Attributes\DataProvider('noConsentCases')]
    public function testWithoutConsentClearsInsteadOfStoring(bool $consent, bool $enabled): void
    {
        $capture = $this->capture(42, $consent, $enabled);
        $this->withdrawal->expects($this->once())->method('execute')->with(42, true, 3);
        $this->assertFalse($capture->capture('masked', 'a@test.com'));
        $this->assertSame([], $this->updates);
    }

    public static function noConsentCases(): array
    {
        return ['no consent / withdrawn' => [false, true], 'module disabled' => [true, false]];
    }

    public function testEmptyEmailWithConsentClearsTheGuestCapture(): void
    {
        $capture = $this->capture();
        $this->withdrawal->expects($this->once())->method('execute')->with(42, true, 3);
        $this->assertFalse($capture->capture('masked', ''));
        $this->assertSame([], $this->updates);
    }

    public function testAWriteRejectedByTheGuardIsNotAcknowledged(): void
    {
        $capture = $this->capture();
        $this->updateResults = [0];
        $this->assertFalse($capture->capture('masked', 'a@test.com'));
        $this->assertContains(['q.is_active = ?', 1], $this->selectWhere);
        $this->assertContains(['q.customer_id IS NULL', null], $this->selectWhere);
        $this->assertContains(["VALID('cid-1')", null], $this->selectWhere);
    }

    public function testIdenticalSuccessfulRetryIsAcknowledged(): void
    {
        $capture = $this->capture();
        $this->updateResults = [0];
        $this->fetchOneResult = 42;
        $this->assertTrue($capture->capture('masked', 'a@test.com'));
        $this->assertContains(['c.contact_id = ?', 7], $this->selectWhere);
        $this->assertContains(['c.consent_id = ?', 'cid-1'], $this->selectWhere);
        $this->assertContains(['c.browser_hash = ?', 'browser-hash'], $this->selectWhere);
    }

    public function testOutOfScopeCartCannotStoreOrClearAndDoesNotRecordConsent(): void
    {
        $this->scopedQuote = null;
        $capture = $this->capture();
        $this->withdrawal->expects($this->never())->method('execute');
        $this->assertFalse($capture->capture('masked', 'a@test.com'));
        $this->assertFalse($capture->capture('masked', ''));
        $this->assertSame([], $this->updates);
        $this->assertNull($this->askedPurpose);
    }

    public function testConsentFromAnotherWebsiteOrPurposeCannotBeCaptured(): void
    {
        $this->scopeMatches = false;
        $this->assertFalse($this->capture()->capture('masked', 'a@test.com'));
        $this->assertSame([], $this->updates);
    }

    public function testAtomicWriteAndRetryKeepTheAuthorizedStore(): void
    {
        $capture = $this->capture();
        $this->updateResults = [0];
        $this->assertFalse($capture->capture('masked', 'a@test.com'));
        $this->assertSame(3, $this->updates[0]['where']['q.store_id = ?']);
        $this->assertContains(['q.store_id = ?', 3], $this->selectWhere);
    }
}
