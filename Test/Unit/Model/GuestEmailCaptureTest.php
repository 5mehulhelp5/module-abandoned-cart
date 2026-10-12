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
        return new GuestEmailCapture($maskFactory, $maskResource, $this->resource(), $consentApi, $this->guard(), $browser, $this->withdrawal, $config, $scope);
    }

    public function testUnknownCartKey(): void
    {
        $this->assertFalse($this->capture(0)->capture('nope', 'a@test.com'));
        $this->assertSame([['nope', 'masked_id']], $this->maskLoads);
        $this->assertSame([], $this->updates);
    }

    public function testStoresEmailWithTheConsentIdForTheCartReminderPurpose(): void
    {
        $this->assertTrue($this->capture()->capture('masked', '  a@test.com '));
        $this->assertSame([['masked', 'masked_id']], $this->maskLoads);
        $this->assertSame('cart_reminder', $this->askedPurpose);
        $this->assertSame([
            'customer_email' => 'a@test.com',
            'ck_abandoned_consent_id' => 'cid-1',
            'ck_abandoned_browser_hash' => 'browser-hash',
        ], $this->updates[0]['data']);
        $where = $this->updates[0]['where'];
        $this->assertSame(42, $where['entity_id = ?']);
        $this->assertSame(1, $where['is_active = ?']);
        $this->assertContains('customer_id IS NULL', self::conditions($where)); // guests only
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
        $this->assertContains(['is_active = ?', 1], $this->selectWhere);
        $this->assertContains(['customer_id IS NULL', null], $this->selectWhere);
        $this->assertContains(["VALID('cid-1')", null], $this->selectWhere);
    }

    public function testIdenticalSuccessfulRetryIsAcknowledged(): void
    {
        $capture = $this->capture();
        $this->updateResults = [0];
        $this->fetchOneResult = 42;
        $this->assertTrue($capture->capture('masked', 'a@test.com'));
        $this->assertContains(['customer_email = ?', 'a@test.com'], $this->selectWhere);
        $this->assertContains(['ck_abandoned_consent_id = ?', 'cid-1'], $this->selectWhere);
        $this->assertContains(['ck_abandoned_browser_hash = ?', 'browser-hash'], $this->selectWhere);
    }

    /** @dataProvider invalidEmails */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEmails')]
    public function testInvalidEmailStoresNothing(string $email): void
    {
        $this->assertFalse($this->capture()->capture('masked', $email));
        $this->assertSame([], $this->updates);
    }

    public static function invalidEmails(): array
    {
        return [['not-an-email'], ['a@'], [str_repeat('a', 250) . '@test.com']];
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
        $this->assertSame(3, $this->updates[0]['where']['store_id = ?']);
        $this->assertContains(['store_id = ?', 3], $this->selectWhere);
    }
}
