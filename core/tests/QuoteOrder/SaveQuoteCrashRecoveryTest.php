<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\QuoteOrder;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Core\Commerce\Contract\CartRepository;
use TriCab\Core\Commerce\Domain\Entity\Cart;
use TriCab\Core\Commerce\Domain\Entity\CartLine;
use TriCab\Core\Commerce\Domain\ValueObject\CartId;
use TriCab\Core\Commerce\Domain\ValueObject\CartLineId;
use TriCab\Core\Commerce\Domain\ValueObject\CartLinePublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode;
use TriCab\Core\Commerce\Domain\ValueObject\CartPublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartStatus;
use TriCab\Core\Customer\Application\DTO\CustomerContactUserLinkDetails;
use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedMarketCodesService;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryVariantCommercialCatalogRepository;
use TriCab\Core\Product\Application\Pricing\PricingActorContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\QuoteOrder\Application\Command\SaveQuoteCommand;
use TriCab\Core\QuoteOrder\Application\DTO\PersistedQuote;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;
use TriCab\Core\QuoteOrder\Application\DTO\SaveQuoteLinePricingSnapshot;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteDisabledException;
use TriCab\Core\QuoteOrder\Application\Service\CartQuoteDraftMapper;
use TriCab\Core\QuoteOrder\Application\Service\QuoteCreateCustomerEligibilityService;
use TriCab\Core\QuoteOrder\Application\Service\SaveQuoteService;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteRepository;
use TriCab\Core\QuoteOrder\Domain\Policy\ProvisionalPlusTwoDayQuoteValidityPolicy;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;
use TriCab\Core\QuoteOrder\Infrastructure\FixedQuoteLegacyOperatorIdResolver;
use TriCab\Core\QuoteOrder\Infrastructure\InMemoryLegacyQuoteWriteConnection;
use TriCab\Core\QuoteOrder\Infrastructure\InMemoryQuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Infrastructure\InMemoryQuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Infrastructure\TransactionalLegacyQuoteWriteRepository;
use TriCab\Core\QuoteOrder\Infrastructure\VariantCodeOnlyQuoteLineLegacyMappingResolver;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;
use TriCab\Core\QuoteOrder\Module;

/**
 * Cross-database crash + cart-failure isolation for Save Quote.
 */
final class SaveQuoteCrashRecoveryTest extends TestCase
{
    public const CART = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const CUSTOMER = '11111111-1111-1111-1111-111111111111';

    public function test_postMysqlCommitCrashRecoveryReturnsExistingQuoteWithoutDuplicate(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $correlation = new InMemoryQuoteWriteCorrelationRepository();
        $idempotency = new InMemoryQuoteWriteIdempotencyStore();
        $writes = new TransactionalLegacyQuoteWriteRepository(
            $this->writeConfig(),
            $connection,
            $correlation,
        );
        $carts = new CrashTestCartRepository();
        $service = $this->service($writes, $idempotency, $correlation, $carts);

        $prepared = $service->prepareFromSnapshots(
            $this->command('crash-key-1'),
            [$this->snapshot()],
            12345,
            'AUD',
        );
        self::assertTrue($prepared->readyToPersist);

        $first = $service->persistPrepared($this->command('crash-key-1'), $prepared);
        self::assertInstanceOf(PersistedQuote::class, $first);
        $insertsAfterFirst = $connection->committedInsertCount();
        self::assertSame(2, $insertsAfterFirst);

        $completed = $idempotency->find(
            $this->actor()->publicId,
            QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT,
            'crash-key-1',
        );
        self::assertNotNull($completed);
        self::assertTrue($completed->stateVo()->isCompleted());

        // Simulate Azure crash before COMPLETED: reset store to IN_PROGRESS; MySQL correlation remains.
        $resetStore = new InMemoryQuoteWriteIdempotencyStore();
        $resetStore->tryAcquire(new QuoteWriteIdempotencyRecord(
            actorKey: $completed->actorKey,
            operation: $completed->operation,
            idempotencyKey: $completed->idempotencyKey,
            requestHash: $completed->requestHash,
            state: QuoteWriteIdempotencyState::IN_PROGRESS,
            platformOperationId: $completed->platformOperationId,
            quoteId: null,
            cartPublicId: $completed->cartPublicId,
            reconciliationFingerprint: $completed->reconciliationFingerprint,
            createdAtUtc: $completed->createdAtUtc,
            updatedAtUtc: $completed->updatedAtUtc,
        ));
        self::assertSame(
            $first->quoteId,
            $correlation->findQuoteIdByPlatformOperationId($completed->platformOperationId),
        );

        $service2 = $this->service($writes, $resetStore, $correlation, $carts);
        // Retry must reuse acquired PlatformOperationId + request hash from the original prepare.
        $retryPrepared = $prepared; // same requestHash; new platformOperationId ignored after acquire
        $replay = $service2->persistPrepared($this->command('crash-key-1'), $retryPrepared);
        self::assertSame($first->quoteId, $replay->quoteId);
        self::assertSame($insertsAfterFirst, $connection->committedInsertCount());
        $after = $resetStore->find(
            $this->actor()->publicId,
            QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT,
            'crash-key-1',
        );
        self::assertTrue($after?->stateVo()->isCompleted());
        self::assertSame($first->quoteId, $after?->quoteId);
    }

    public function test_cartMarkSubmittedFailureDoesNotDuplicateQuote(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $correlation = new InMemoryQuoteWriteCorrelationRepository();
        $idempotency = new InMemoryQuoteWriteIdempotencyStore();
        $writes = new TransactionalLegacyQuoteWriteRepository(
            $this->writeConfig(),
            $connection,
            $correlation,
        );
        $carts = new CrashTestCartRepository(failOnMarkSubmitted: true);
        $service = $this->service($writes, $idempotency, $correlation, $carts);

        $prepared = $service->prepareFromSnapshots(
            $this->command('cart-fail-1'),
            [$this->snapshot()],
            12345,
            'AUD',
        );
        $first = $service->persistPrepared($this->command('cart-fail-1'), $prepared);
        self::assertSame(1, $carts->markSubmittedAttempts);
        $inserts = $connection->committedInsertCount();

        $replay = $service->persistPrepared($this->command('cart-fail-1'), $prepared);
        self::assertSame($first->quoteId, $replay->quoteId);
        self::assertSame($inserts, $connection->committedInsertCount());
        self::assertGreaterThanOrEqual(2, $carts->markSubmittedAttempts);
    }

    public function test_disabledFlagProducesZeroSql(): void
    {
        $connection = new InMemoryLegacyQuoteWriteConnection();
        $correlation = new InMemoryQuoteWriteCorrelationRepository();
        $writes = new TransactionalLegacyQuoteWriteRepository(
            new LegacyQuoteWriteConfig(false, null, null, null, null, '_dev', true),
            $connection,
            $correlation,
        );
        $service = $this->service(
            $writes,
            new InMemoryQuoteWriteIdempotencyStore(),
            $correlation,
            new CrashTestCartRepository(),
            writeEnabled: false,
        );
        $prepared = $service->prepareFromSnapshots(
            $this->command('disabled-1'),
            [$this->snapshot()],
            12345,
            'AUD',
        );

        try {
            $service->persistPrepared($this->command('disabled-1'), $prepared);
            self::fail('Expected disabled');
        } catch (QuoteWriteDisabledException) {
            self::assertSame([], $connection->committedOperations());
            self::assertSame([], $connection->pendingOperations());
        }
    }

    private function service(
        QuoteWriteRepository $writes,
        InMemoryQuoteWriteIdempotencyStore $idempotency,
        InMemoryQuoteWriteCorrelationRepository $correlation,
        CrashTestCartRepository $carts,
        bool $writeEnabled = true,
    ): SaveQuoteService {
        $pricingActor = new class implements PricingActorContextResolver {
            public function fromAuthenticatedUser(
                ?AuthenticatedUser $user,
                ?string $pricingContextToken = null,
            ): CustomerPricingContext {
                return CustomerPricingContext::guest();
            }
        };

        return new SaveQuoteService(
            eligibility: $this->eligibility(),
            customers: $this->customerRepo(),
            carts: $carts,
            pricingActor: $pricingActor,
            sellPricing: new ResolveCustomerSellPriceService(
                new InMemoryVariantCommercialCatalogRepository(),
                new InMemoryCustomerPricingMarginRepository([]),
                new InMemoryCurrencyFxRateRepository([]),
            ),
            legacyLineMapping: new VariantCodeOnlyQuoteLineLegacyMappingResolver(),
            operatorIds: new FixedQuoteLegacyOperatorIdResolver(42),
            draftMapper: new CartQuoteDraftMapper(new ProvisionalPlusTwoDayQuoteValidityPolicy()),
            validity: new ProvisionalPlusTwoDayQuoteValidityPolicy(),
            writes: $writes,
            idempotency: $idempotency,
            writeConfig: $writeEnabled ? $this->writeConfig() : LegacyQuoteWriteConfig::fromEnvironment([]),
            correlation: $correlation,
        );
    }

    private function command(string $idempotencyKey): SaveQuoteCommand
    {
        return new SaveQuoteCommand(
            actor: $this->actor(),
            customerPublicId: self::CUSTOMER,
            cartPublicId: self::CART,
            reference: 'PHASE4C1-DEV-FIXTURE',
            dryRun: false,
            idempotencyKey: $idempotencyKey,
            raisedByOperatorIdOverride: 42,
        );
    }

    private function snapshot(): SaveQuoteLinePricingSnapshot
    {
        return new SaveQuoteLinePricingSnapshot(
            variantPublicId: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            productCode: 'ABC123',
            categoryType: 1615,
            packCount: 1,
            unitsOrLength: '100',
            sellUnitPrice: '2.00',
            lineNet: '200.00',
            costUnitPrice: '1.00',
            costValue: '100.00',
            marginPercent: '50',
            pricingStatus: 'RESOLVED',
            marginSource: 'TMMKTM',
            colourId: 4008,
            packMatrixId: 1501,
        );
    }

    private function writeConfig(): LegacyQuoteWriteConfig
    {
        return new LegacyQuoteWriteConfig(
            enabled: true,
            dsn: 'mysql:host=test;dbname=tc',
            user: 'writer',
            password: 'x',
            sslMode: null,
            tableSuffix: '_dev',
            tableSuffixConfigured: true,
        );
    }

    private function actor(): AuthenticatedUser
    {
        return new AuthenticatedUser(
            userId: 1,
            publicId: '99999999-9999-9999-9999-999999999999',
            emailAddress: 'staff@tricab.com',
            displayName: 'Staff',
            status: 'ACTIVE',
            roles: ['ADMINISTRATOR'],
            permissions: [Module::PERMISSION_CREATE, 'CUSTOMER.PRICE_AS', 'CUSTOMER.READ', 'QUOTE.READ'],
        );
    }

    private function eligibility(): QuoteCreateCustomerEligibilityService
    {
        $links = $this->createMock(CustomerContactUserRepository::class);
        $links->method('listActiveLinksForUser')->willReturn([]);

        return new QuoteCreateCustomerEligibilityService(
            $this->customerRepo(),
            new ResolveAuthenticatedCustomerContextService(
                $links,
                new ResolveAuthenticatedMarketCodesService($this->customerRepo()),
            ),
        );
    }

    private function customerRepo(): CustomerRepository
    {
        $customers = $this->createMock(CustomerRepository::class);
        $customers->method('findDetailsByPublicId')->willReturn(
            new CustomerDetails(
                publicId: self::CUSTOMER,
                customerCode: 'TEST01',
                customerName: 'Test Customer',
                status: 'ACTIVE',
                isActive: true,
                addressLine1: null,
                addressLine2: null,
                suburb: null,
                postcode: null,
                countryCode: 'AU',
                marketCode: 'AU',
                salesRep: null,
                territory: 'SA',
                phone: null,
                legacyCustomerId: 12345,
                createdAtUtc: '2026-01-01T00:00:00Z',
                updatedAtUtc: '2026-01-01T00:00:00Z',
            ),
        );

        return $customers;
    }
}

final class CrashTestCartRepository implements CartRepository
{
    public int $markSubmittedAttempts = 0;

    private bool $submitted = false;

    public function __construct(private readonly bool $failOnMarkSubmitted = false)
    {
    }

    public function findOpenCartForActor(
        int $ownerUserId,
        ?int $effectiveCustomerId,
        CartPricingMode $pricingMode,
        string $currencyCode,
    ): ?Cart {
        return null;
    }

    public function getByPublicId(CartPublicId $cartPublicId, int $ownerUserId): ?Cart
    {
        return new Cart(
            id: CartId::fromInt(7),
            publicId: $cartPublicId,
            ownerUserId: $ownerUserId,
            effectiveCustomerId: 1,
            pricingMode: CartPricingMode::fromString(CartPricingMode::STAFF_SELECTED_CUSTOMER),
            currencyCode: 'AUD',
            status: $this->submitted ? CartStatus::fromString(CartStatus::SUBMITTED) : CartStatus::open(),
            pricingFingerprint: null,
            rowVersion: RowVersionToken::fromBase64(base64_encode(str_repeat("\0", 8))),
            lines: [],
        );
    }

    public function createCart(
        int $ownerUserId,
        ?int $effectiveCustomerId,
        CartPricingMode $pricingMode,
        string $currencyCode,
        ?int $actorUserId = null,
    ): Cart {
        throw new RuntimeException('not used');
    }

    public function listLines(CartId $cartId): array
    {
        return [];
    }

    public function addOrMergeLine(CartId $cartId, CartLine $line, ?int $actorUserId = null): CartLine
    {
        throw new RuntimeException('not used');
    }

    public function updateLine(
        CartId $cartId,
        CartLinePublicId $linePublicId,
        CartLine $line,
        RowVersionToken $expectedRowVersion,
        ?int $actorUserId = null,
    ): CartLine {
        throw new RuntimeException('not used');
    }

    public function softRemoveLine(
        CartId $cartId,
        CartLinePublicId $linePublicId,
        RowVersionToken $expectedRowVersion,
        ?int $actorUserId = null,
    ): void {
    }

    public function touchCartUpdatedAt(CartId $cartId, ?int $actorUserId = null): void
    {
    }

    public function markSubmitted(CartId $cartId, ?int $actorUserId = null): Cart
    {
        ++$this->markSubmittedAttempts;
        if ($this->failOnMarkSubmitted) {
            throw new RuntimeException('Simulated cart markSubmitted failure');
        }
        $this->submitted = true;

        return $this->getByPublicId(CartPublicId::fromString(SaveQuoteCrashRecoveryTest::CART), $actorUserId ?? 1)
            ?? throw new RuntimeException('cart missing');
    }

    public function abandon(CartId $cartId, ?int $actorUserId = null): Cart
    {
        throw new RuntimeException('not used');
    }

    public function resolveProductVariantId(string $variantPublicId): ?int
    {
        return 1;
    }

    public function resolveVariantDisplay(int $productVariantId): ?array
    {
        return [
            'publicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'code' => 'ABC123',
            'displayName' => 'Cable',
        ];
    }

    public function findLineByPublicId(CartLinePublicId $linePublicId, int $ownerUserId): ?CartLine
    {
        return null;
    }
}
