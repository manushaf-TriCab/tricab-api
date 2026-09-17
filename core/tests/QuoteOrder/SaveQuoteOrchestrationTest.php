<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\QuoteOrder;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Customer\Application\DTO\CustomerContactUserLinkDetails;
use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedMarketCodesService;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\QuoteOrder\Application\Command\SaveQuoteCommand;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;
use TriCab\Core\QuoteOrder\Application\DTO\SaveQuoteLinePricingSnapshot;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteCreateAuthorizationException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteDisabledException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteIdempotencyConflictException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteMisconfiguredException;
use TriCab\Core\QuoteOrder\Application\Service\CartQuoteDraftMapper;
use TriCab\Core\QuoteOrder\Application\Service\QuoteCreateCustomerEligibilityService;
use TriCab\Core\QuoteOrder\Application\Service\SaveQuoteService;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Domain\Policy\ProvisionalPlusTwoDayQuoteValidityPolicy;
use TriCab\Core\QuoteOrder\Domain\Policy\QuoteHeaderDefaults;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;
use TriCab\Core\QuoteOrder\Infrastructure\DisabledQuoteWriteRepository;
use TriCab\Core\QuoteOrder\Infrastructure\FixedQuoteLegacyOperatorIdResolver;
use TriCab\Core\QuoteOrder\Infrastructure\InMemoryQuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Infrastructure\VariantCodeOnlyQuoteLineLegacyMappingResolver;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;
use TriCab\Core\QuoteOrder\Live\NonProductionQuoteWriteSafety;
use TriCab\Core\QuoteOrder\Module;

final class SaveQuoteOrchestrationTest extends TestCase
{
    private const CUSTOMER_A = '11111111-1111-1111-1111-111111111111';
    private const CUSTOMER_B = '22222222-2222-2222-2222-222222222222';

    public function test_missingQuoteCreatePermission(): void
    {
        $this->expectException(QuoteCreateAuthorizationException::class);
        $this->eligibility([self::CUSTOMER_A])->assertCanCreateQuoteFor(
            $this->actor(['EMPLOYEE'], ['QUOTE.READ', 'CUSTOMER.PRICE_AS']),
            self::CUSTOMER_A,
        );
    }

    public function test_staffWithCreateAndPriceAsMayCreateForCustomer(): void
    {
        self::assertTrue(
            $this->eligibility([self::CUSTOMER_A])->canCreateQuoteFor(
                $this->actor(['EMPLOYEE'], [Module::PERMISSION_CREATE, 'CUSTOMER.PRICE_AS']),
                self::CUSTOMER_A,
            ),
        );
    }

    public function test_staffWithoutPriceAsCannotCreateEvenWithQuoteCreate(): void
    {
        self::assertFalse(
            $this->eligibility([self::CUSTOMER_A])->canCreateQuoteFor(
                $this->actor(['EMPLOYEE'], [Module::PERMISSION_CREATE]),
                self::CUSTOMER_A,
            ),
        );
    }

    public function test_portalOwnCustomerAllowed(): void
    {
        self::assertTrue(
            $this->eligibility([self::CUSTOMER_A])->canCreateQuoteFor(
                $this->actor(['CUSTOMER_ADMIN'], [Module::PERMISSION_CREATE]),
                self::CUSTOMER_A,
            ),
        );
    }

    public function test_portalForeignCustomerDenied(): void
    {
        self::assertFalse(
            $this->eligibility([self::CUSTOMER_A])->canCreateQuoteFor(
                $this->actor(['CUSTOMER_ADMIN'], [Module::PERMISSION_CREATE]),
                self::CUSTOMER_B,
            ),
        );
    }

    public function test_dryRunMapsPersistQuoteDraftWithoutSql(): void
    {
        $result = $this->saveQuoteService()->prepareFromSnapshots(
            new SaveQuoteCommand(
                actor: $this->actor(['EMPLOYEE'], [Module::PERMISSION_CREATE, 'CUSTOMER.PRICE_AS']),
                customerPublicId: self::CUSTOMER_A,
                cartPublicId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                reference: 'PROJ-1',
                dryRun: true,
                raisedByOperatorIdOverride: 42,
            ),
            [
                new SaveQuoteLinePricingSnapshot(
                    variantPublicId: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                    productCode: 'ABC123',
                    categoryType: 1615,
                    packCount: 2,
                    unitsOrLength: '500',
                    sellUnitPrice: '1.50',
                    lineNet: '1500.00',
                    costUnitPrice: '0.75',
                    costValue: '750.00',
                    marginPercent: '50',
                    pricingStatus: 'RESOLVED',
                    marginSource: 'TMMKTM',
                ),
            ],
            12345,
            'AUD',
        );

        self::assertTrue($result->readyToPersist);
        self::assertSame([], $result->blockers);
        self::assertNotNull($result->draft);
        self::assertSame('1705', $result->statusCode);
        self::assertSame('AUD', $result->currencyCode);
        self::assertSame('1500.00', $result->totalNet);
        self::assertSame('resolved', $result->totalStatus);
        self::assertFalse($result->hasTbcLines);
        self::assertSame(
            ProvisionalPlusTwoDayQuoteValidityPolicy::CONFIRMATION_STATUS,
            $result->validityPolicyStatus,
        );
        self::assertSame(1611, $result->mappedPreview['header']['dt']);
        self::assertSame(12345, $result->mappedPreview['header']['cus']);
        self::assertSame('1500.00', $result->mappedPreview['totalNet']);
    }

    public function test_tbcDraftAllowedWithPendingTotal(): void
    {
        $result = $this->saveQuoteService()->prepareFromSnapshots(
            new SaveQuoteCommand(
                actor: $this->actor(['EMPLOYEE'], [Module::PERMISSION_CREATE, 'CUSTOMER.PRICE_AS']),
                customerPublicId: self::CUSTOMER_A,
                cartPublicId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                reference: 'TBC-1',
                dryRun: true,
                raisedByOperatorIdOverride: 42,
            ),
            [
                new SaveQuoteLinePricingSnapshot(
                    variantPublicId: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                    productCode: 'ABC123',
                    categoryType: 1615,
                    packCount: 1,
                    unitsOrLength: '100',
                    sellUnitPrice: '0.00',
                    lineNet: '0.00',
                    costUnitPrice: '0.00',
                    costValue: '0.00',
                    marginPercent: null,
                    pricingStatus: 'TBC',
                    marginSource: 'TBC',
                ),
            ],
            12345,
            'AUD',
        );

        self::assertTrue($result->readyToPersist);
        self::assertTrue($result->hasTbcLines);
        self::assertSame('pending', $result->totalStatus);
        self::assertSame('0.00', $result->totalNet);
    }

    public function test_disabledWriteRepositoryBlocksPersist(): void
    {
        $prepared = $this->saveQuoteService()->prepareFromSnapshots(
            new SaveQuoteCommand(
                actor: $this->actor(['EMPLOYEE'], [Module::PERMISSION_CREATE, 'CUSTOMER.PRICE_AS']),
                customerPublicId: self::CUSTOMER_A,
                cartPublicId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                reference: 'PROJ-1',
                dryRun: true,
                raisedByOperatorIdOverride: 42,
            ),
            [
                new SaveQuoteLinePricingSnapshot(
                    variantPublicId: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                    productCode: 'ABC123',
                    categoryType: 1615,
                    packCount: 1,
                    unitsOrLength: '10',
                    sellUnitPrice: '2.00',
                    lineNet: '20.00',
                    costUnitPrice: '1.00',
                    costValue: '10.00',
                    marginPercent: '50',
                    pricingStatus: 'RESOLVED',
                    marginSource: 'TMMKTM',
                ),
            ],
            12345,
            'AUD',
        );

        self::assertNotNull($prepared->draft);
        $this->expectException(QuoteWriteDisabledException::class);
        (new DisabledQuoteWriteRepository())->persistDraft($prepared->draft);
    }

    public function test_idempotencySameRequestAndConflict(): void
    {
        $store = new InMemoryQuoteWriteIdempotencyStore();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $base = new QuoteWriteIdempotencyRecord(
            actorKey: 'actor-1',
            operation: QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT,
            idempotencyKey: 'k1',
            requestHash: hash('sha256', 'a'),
            state: QuoteWriteIdempotencyState::IN_PROGRESS,
            platformOperationId: '11111111-1111-4111-8111-111111111111',
            quoteId: null,
            cartPublicId: null,
            reconciliationFingerprint: 'fp',
            createdAtUtc: $now,
            updatedAtUtc: $now,
        );
        self::assertSame(
            $base->platformOperationId,
            $store->tryAcquire($base)->platformOperationId,
        );
        self::assertSame(
            $base->platformOperationId,
            $store->tryAcquire($base)->platformOperationId,
        );

        try {
            $store->tryAcquire(new QuoteWriteIdempotencyRecord(
                actorKey: 'actor-1',
                operation: QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT,
                idempotencyKey: 'k1',
                requestHash: hash('sha256', 'b'),
                state: QuoteWriteIdempotencyState::IN_PROGRESS,
                platformOperationId: '22222222-2222-4222-8222-222222222222',
                quoteId: null,
                cartPublicId: null,
                reconciliationFingerprint: null,
                createdAtUtc: $now,
                updatedAtUtc: $now,
            ));
            self::fail('Expected conflict');
        } catch (QuoteWriteIdempotencyConflictException) {
            // expected
        }

        $store->markCompleted('actor-1', QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT, 'k1', '100000001');
        $done = $store->find('actor-1', QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT, 'k1');
        self::assertTrue($done?->stateVo()->isCompleted());
        self::assertSame('100000001', $done?->quoteId);
    }

    public function test_validityAndHeaderDefaults(): void
    {
        $policy = new ProvisionalPlusTwoDayQuoteValidityPolicy();
        $created = new DateTimeImmutable('2026-09-18 10:00:00', new DateTimeZone('Australia/Melbourne'));
        self::assertSame('2026-09-20', $policy->validUntil($created)->format('Y-m-d'));
        self::assertSame(
            ProvisionalPlusTwoDayQuoteValidityPolicy::CONFIRMATION_STATUS,
            $policy->confirmationStatus(),
        );
        self::assertCount(5, QuoteHeaderDefaults::catalogue());
        self::assertSame('QUOTE.CREATE', Module::PERMISSION_CREATE);
    }

    public function test_nonProductionWriteRejectsEmptySuffix(): void
    {
        $this->expectException(QuoteWriteMisconfiguredException::class);
        (new NonProductionQuoteWriteSafety())->assertDevTableSuffixRequired(
            new LegacyQuoteWriteConfig(
                enabled: true,
                dsn: 'mysql:host=x;dbname=tc',
                user: 'w',
                password: 'p',
                sslMode: null,
                tableSuffix: '',
                tableSuffixConfigured: true,
            ),
        );
    }

    private function saveQuoteService(): SaveQuoteService
    {
        $customers = $this->customerRepoMock();
        $pricingActor = new class implements \TriCab\Core\Product\Application\Pricing\PricingActorContextResolver {
            public function fromAuthenticatedUser(
                ?\TriCab\Core\Identity\Application\DTO\AuthenticatedUser $user,
                ?string $pricingContextToken = null,
            ): \TriCab\Core\Product\Application\Pricing\CustomerPricingContext {
                return \TriCab\Core\Product\Application\Pricing\CustomerPricingContext::guest();
            }
        };

        return new SaveQuoteService(
            eligibility: $this->eligibility([self::CUSTOMER_A]),
            customers: $customers,
            carts: $this->createStub(\TriCab\Core\Commerce\Contract\CartRepository::class),
            pricingActor: $pricingActor,
            sellPricing: new \TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService(
                new \TriCab\Core\Product\Application\Pricing\InMemoryVariantCommercialCatalogRepository(),
                new \TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository([]),
                new \TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository([]),
            ),
            legacyLineMapping: new VariantCodeOnlyQuoteLineLegacyMappingResolver(),
            operatorIds: new FixedQuoteLegacyOperatorIdResolver(42),
            draftMapper: new CartQuoteDraftMapper(new ProvisionalPlusTwoDayQuoteValidityPolicy()),
            validity: new ProvisionalPlusTwoDayQuoteValidityPolicy(),
            writes: new DisabledQuoteWriteRepository(),
            idempotency: new InMemoryQuoteWriteIdempotencyStore(),
            writeConfig: LegacyQuoteWriteConfig::fromEnvironment([]),
        );
    }

    /**
     * @param list<string> $portalLinks
     */
    private function eligibility(array $portalLinks): QuoteCreateCustomerEligibilityService
    {
        $customers = $this->customerRepoMock();
        $links = $this->createMock(CustomerContactUserRepository::class);
        $links->method('listActiveLinksForUser')->willReturnCallback(
            function (UserId $userId) use ($portalLinks): array {
                $out = [];
                foreach ($portalLinks as $i => $customerPublicId) {
                    $out[] = new CustomerContactUserLinkDetails(
                        contactPublicId: sprintf('cccccccc-cccc-cccc-cccc-%012d', $i + 1),
                        customerPublicId: $customerPublicId,
                        userPublicId: '99999999-9999-9999-9999-999999999999',
                        isPrimary: true,
                        isActive: true,
                        linkedAtUtc: '2026-01-01T00:00:00Z',
                    );
                }

                return $out;
            },
        );

        return new QuoteCreateCustomerEligibilityService(
            $customers,
            new ResolveAuthenticatedCustomerContextService(
                $links,
                new ResolveAuthenticatedMarketCodesService($customers),
            ),
        );
    }

    private function customerRepoMock(): CustomerRepository
    {
        $repo = $this->createMock(CustomerRepository::class);
        $repo->method('findDetailsByPublicId')->willReturnCallback(
            function (CustomerPublicId $publicId): ?CustomerDetails {
                $id = strtolower($publicId->toString());
                if (!in_array($id, [self::CUSTOMER_A, self::CUSTOMER_B], true)) {
                    return null;
                }

                return new CustomerDetails(
                    publicId: $id,
                    customerCode: 'ACME',
                    customerName: 'Acme',
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
                );
            },
        );

        return $repo;
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function actor(array $roles, array $permissions): AuthenticatedUser
    {
        return new AuthenticatedUser(
            userId: 1,
            publicId: '99999999-9999-9999-9999-999999999999',
            emailAddress: 'staff@tricab.com',
            displayName: 'Staff',
            status: 'ACTIVE',
            roles: $roles,
            permissions: $permissions,
        );
    }
}
