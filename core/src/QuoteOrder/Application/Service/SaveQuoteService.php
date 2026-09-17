<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TriCab\Core\Commerce\Contract\CartRepository;
use TriCab\Core\Commerce\Domain\Exception\CartAccessDenied;
use TriCab\Core\Commerce\Domain\ValueObject\CartPublicId;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\PricingActorContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;
use TriCab\Core\QuoteOrder\Application\Command\SaveQuoteCommand;
use TriCab\Core\QuoteOrder\Application\DTO\PersistedQuote;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;
use TriCab\Core\QuoteOrder\Application\DTO\SaveQuoteLinePricingSnapshot;
use TriCab\Core\QuoteOrder\Application\DTO\SaveQuotePreparationResult;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteCreateAuthorizationException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteDisabledException;
use TriCab\Core\QuoteOrder\Application\Exception\SaveQuoteValidationException;
use TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver;
use TriCab\Core\QuoteOrder\Contract\QuoteLineLegacyMappingResolver;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteRepository;
use TriCab\Core\QuoteOrder\Domain\Mapping\LegacyQuoteStatusMapper;
use TriCab\Core\QuoteOrder\Domain\Policy\QuoteValidityPolicy;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;
use TriCab\Core\QuoteOrder\Infrastructure\NullQuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;
use TriCab\Core\QuoteOrder\Live\NonProductionQuoteWriteSafety;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Save Quote orchestration.
 *
 * State machine (conceptual; not all states are DB-persisted):
 * VALIDATING → PRICING → IDEMPOTENCY_ACQUIRED → PERSISTING_LEGACY →
 * LEGACY_COMMITTED → IDEMPOTENCY_COMPLETED → CART_SUBMITTED → COMPLETED
 *
 * Critical boundaries:
 * - LEGACY_COMMITTED but IDEMPOTENCY_COMPLETED failed → recover via MySQL correlation
 * - IDEMPOTENCY_COMPLETED but CART_SUBMITTED failed → return quote; do not create duplicate
 *
 * Does NOT register HTTP routes.
 */
final class SaveQuoteService
{
    public function __construct(
        private readonly QuoteCreateCustomerEligibilityService $eligibility,
        private readonly CustomerRepository $customers,
        private readonly CartRepository $carts,
        private readonly PricingActorContextResolver $pricingActor,
        private readonly ResolveCustomerSellPriceService $sellPricing,
        private readonly QuoteLineLegacyMappingResolver $legacyLineMapping,
        private readonly QuoteLegacyOperatorIdResolver $operatorIds,
        private readonly CartQuoteDraftMapper $draftMapper,
        private readonly QuoteValidityPolicy $validity,
        private readonly QuoteWriteRepository $writes,
        private readonly QuoteWriteIdempotencyStore $idempotency,
        private readonly LegacyQuoteWriteConfig $writeConfig,
        private readonly QuoteWriteCorrelationRepository $correlation = new NullQuoteWriteCorrelationRepository(),
        private readonly NonProductionQuoteWriteSafety $writeSafety = new NonProductionQuoteWriteSafety(),
        private readonly Clock $clock = new SystemClock(),
        private readonly LegacyQuoteStatusMapper $statusMapper = new LegacyQuoteStatusMapper(),
    ) {
    }

    /**
     * Full prepare path: auth → eligibility → cart → reprice → map.
     * Never writes MySQL when $command->dryRun is true (default).
     */
    public function prepare(SaveQuoteCommand $command): SaveQuotePreparationResult
    {
        if (!$this->eligibility->hasCreatePermission($command->actor)) {
            throw QuoteCreateAuthorizationException::missingPermission();
        }

        $this->eligibility->assertCanCreateQuoteFor($command->actor, $command->customerPublicId);

        $customer = $this->customers->findDetailsByPublicId(
            CustomerPublicId::fromString($command->customerPublicId),
        );
        if ($customer === null) {
            throw QuoteCreateAuthorizationException::customerNotFound();
        }

        $cart = $this->carts->getByPublicId(
            CartPublicId::fromString($command->cartPublicId),
            $command->actor->userId,
        );
        if ($cart === null) {
            throw CartAccessDenied::notOwner();
        }

        $blockers = [];
        if (!$cart->status()->isOpen()) {
            $blockers[] = 'CART_NOT_OPEN';
        }
        if ($cart->lines() === []) {
            $blockers[] = 'CART_EMPTY';
        }

        $pricingContext = $this->pricingActor->fromAuthenticatedUser(
            $command->actor,
            $command->pricingContextToken,
        );
        if (($pricingContext->customerPublicId ?? '') === ''
            || strcasecmp((string) $pricingContext->customerPublicId, $command->customerPublicId) !== 0
        ) {
            $blockers[] = 'PRICING_CONTEXT_CUSTOMER_MISMATCH';
        }

        if ($customer->legacyCustomerId === null || $customer->legacyCustomerId <= 0) {
            $blockers[] = 'MISSING_LEGACY_CUSTOMER_ID';
        }

        $customerGenerated = $this->isPortalActor($command->actor);
        $raisedBy = $command->raisedByOperatorIdOverride
            ?? $this->operatorIds->resolveRaisedByOperatorId($command->actor, $customerGenerated);
        if ($raisedBy === null || $raisedBy <= 0) {
            $blockers[] = 'MISSING_LEGACY_OPERATOR_ID';
        }

        $snapshots = [];
        $hasTbc = false;
        foreach ($cart->lines() as $line) {
            $resolved = $this->sellPricing->resolve(
                $line->variantPublicId(),
                $pricingContext,
                true,
                null,
                false,
                $line->length(),
            );
            $diagnostic = $resolved instanceof CustomerVariantSellPriceDiagnostic ? $resolved : null;
            $customerResult = $diagnostic?->customer ?? $resolved;

            $legacy = $this->legacyLineMapping->resolve(
                $line->variantPublicId(),
                $line->variantCode() ?? $customerResult->variantCode,
                $line->length(),
            );
            if ($legacy === null) {
                $blockers[] = 'MISSING_LEGACY_LINE_MAPPING:' . $line->variantPublicId();
                continue;
            }

            $isTbc = $customerResult->status === CustomerSellPriceStatus::TBC
                || $customerResult->unitSellPrice === null
                || bccomp((string) $customerResult->unitSellPrice, '0', 6) <= 0;

            if ($isTbc) {
                $hasTbc = true;
                $snapshots[] = new SaveQuoteLinePricingSnapshot(
                    variantPublicId: $line->variantPublicId(),
                    productCode: $legacy['productCode'],
                    categoryType: $legacy['categoryType'],
                    packCount: $line->quantity(),
                    unitsOrLength: $line->length() ?? '1',
                    sellUnitPrice: '0.00',
                    lineNet: '0.00',
                    costUnitPrice: '0.00',
                    costValue: '0.00',
                    marginPercent: null,
                    pricingStatus: 'TBC',
                    marginSource: 'TBC',
                    colourId: $legacy['colourId'],
                    packMatrixId: $legacy['packMatrixId'] ?? null,
                );
                continue;
            }

            if ($customerResult->status !== CustomerSellPriceStatus::RESOLVED) {
                $blockers[] = 'LINE_NOT_PRICEABLE:' . $line->variantPublicId();
                continue;
            }

            $unitSell = (string) $customerResult->unitSellPrice;
            $units = $line->length() ?? '1';
            $lineNet = bcmul(bcmul($unitSell, (string) $line->quantity(), 6), $units, 2);
            $costUnit = $diagnostic?->baseCost !== null && is_numeric($diagnostic->baseCost)
                ? bcadd((string) $diagnostic->baseCost, '0', 6)
                : '0.00';
            if ($diagnostic?->baseCost === null) {
                $blockers[] = 'MISSING_LINE_COST:' . $line->variantPublicId();
            }
            $costValue = bcmul(bcmul($costUnit, (string) $line->quantity(), 6), $units, 2);
            $margin = $diagnostic?->resolvedMarginPercent
                ?? $diagnostic?->overrideMarginPercent
                ?? $diagnostic?->marginPercent;

            if (($line->marginSource() === 'STAFF_OVERRIDE' || ($diagnostic?->overrideMarginPercent ?? null) !== null)
                && ($diagnostic?->overrideMarginPercent ?? null) === null
                && ($pricingContext->overrideMarginPercent ?? null) === null
            ) {
                $blockers[] = 'MARGIN_OVERRIDE_NOT_AUTHORIZED_OR_EXPIRED:' . $line->variantPublicId();
            }

            $snapshots[] = new SaveQuoteLinePricingSnapshot(
                variantPublicId: $line->variantPublicId(),
                productCode: $legacy['productCode'],
                categoryType: $legacy['categoryType'],
                packCount: $line->quantity(),
                unitsOrLength: $units,
                sellUnitPrice: $unitSell,
                lineNet: $lineNet,
                costUnitPrice: $costUnit,
                costValue: $costValue,
                marginPercent: $margin !== null ? (string) $margin : null,
                pricingStatus: 'RESOLVED',
                marginSource: $diagnostic?->marginSource ?? $line->marginSource(),
                colourId: $legacy['colourId'],
                packMatrixId: $legacy['packMatrixId'] ?? null,
            );
        }

        return $this->buildPreparation(
            $command,
            $blockers,
            $snapshots,
            $hasTbc,
            $customer->legacyCustomerId,
            $raisedBy,
            $customerGenerated,
            strtoupper($cart->currencyCode()),
        );
    }

    /**
     * Test / dry-run entry: skip cart load; build from supplied snapshots.
     *
     * @param list<SaveQuoteLinePricingSnapshot> $snapshots
     */
    public function prepareFromSnapshots(
        SaveQuoteCommand $command,
        array $snapshots,
        int $legacyCustomerId,
        string $currencyCode,
    ): SaveQuotePreparationResult {
        if (!$this->eligibility->hasCreatePermission($command->actor)) {
            throw QuoteCreateAuthorizationException::missingPermission();
        }
        $this->eligibility->assertCanCreateQuoteFor($command->actor, $command->customerPublicId);

        $customerGenerated = $this->isPortalActor($command->actor);
        $raisedBy = $command->raisedByOperatorIdOverride
            ?? $this->operatorIds->resolveRaisedByOperatorId($command->actor, $customerGenerated);
        $blockers = [];
        if ($legacyCustomerId <= 0) {
            $blockers[] = 'MISSING_LEGACY_CUSTOMER_ID';
        }
        if ($raisedBy === null || $raisedBy <= 0) {
            $blockers[] = 'MISSING_LEGACY_OPERATOR_ID';
        }
        if ($snapshots === []) {
            $blockers[] = 'CART_EMPTY';
        }
        $hasTbc = false;
        foreach ($snapshots as $snap) {
            if ($snap->isTbc()) {
                $hasTbc = true;
            }
        }

        return $this->buildPreparation(
            $command,
            $blockers,
            $snapshots,
            $hasTbc,
            $legacyCustomerId,
            $raisedBy,
            $customerGenerated,
            strtoupper($currencyCode),
        );
    }

    /**
     * Persist path. Default dryRun=true. Write flag + _dev safety enforced before mutation.
     */
    public function execute(SaveQuoteCommand $command): SaveQuotePreparationResult|PersistedQuote
    {
        $prepared = $this->prepare($command);
        if ($command->dryRun) {
            return $prepared;
        }

        return $this->persistPrepared($command, $prepared);
    }

    /**
     * Persist an already-prepared draft (used by execute + crash-recovery tests).
     */
    public function persistPrepared(
        SaveQuoteCommand $command,
        SaveQuotePreparationResult $prepared,
    ): PersistedQuote {
        if (!$prepared->readyToPersist || $prepared->draft === null) {
            throw SaveQuoteValidationException::because(
                'Save Quote is not ready to persist.',
                $prepared->blockers,
            );
        }

        $this->writeSafety->assertDevTableSuffixRequired($this->writeConfig);
        if (!$this->writeConfig->enabled) {
            throw QuoteWriteDisabledException::disabled();
        }

        $actorKey = $command->actor->publicId;
        $idemKey = trim((string) $command->idempotencyKey);
        if ($idemKey === '') {
            throw SaveQuoteValidationException::because('Idempotency-Key is required for quote persistence.');
        }

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $acquire = $this->idempotency->tryAcquire(new QuoteWriteIdempotencyRecord(
            actorKey: $actorKey,
            operation: QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT,
            idempotencyKey: $idemKey,
            requestHash: (string) $prepared->requestHash,
            state: QuoteWriteIdempotencyState::IN_PROGRESS,
            platformOperationId: (string) $prepared->platformOperationId,
            quoteId: null,
            cartPublicId: $command->cartPublicId,
            reconciliationFingerprint: $prepared->reconciliationFingerprint,
            createdAtUtc: $now,
            updatedAtUtc: $now,
        ));

        // Always use the acquired PlatformOperationId (stable across retries).
        $operationId = $acquire->platformOperationId;
        $draft = $this->draftWithPlatformOperationId($prepared->draft, $operationId);

        if ($acquire->stateVo()->isCompleted() && $acquire->quoteId !== null) {
            $this->tryMarkCartSubmitted($command);

            return new PersistedQuote(
                quoteId: $acquire->quoteId,
                statusCode: $prepared->statusCode,
                totalNet: $prepared->totalNet,
                lineIds: [],
            );
        }

        // Cross-DB crash: Azure IN_PROGRESS, MySQL already committed + correlated.
        if ($acquire->stateVo()->isInProgress()) {
            $recoveredId = $this->correlation->findQuoteIdByPlatformOperationId($operationId);
            if ($recoveredId !== null && $recoveredId !== '' && $recoveredId !== '0') {
                $this->idempotency->markCompleted(
                    $actorKey,
                    QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT,
                    $idemKey,
                    $recoveredId,
                );
                $this->tryMarkCartSubmitted($command);

                return new PersistedQuote(
                    quoteId: $recoveredId,
                    statusCode: $prepared->statusCode,
                    totalNet: $prepared->totalNet,
                    lineIds: [],
                );
            }
        }

        try {
            $persisted = $this->writes->persistDraft($draft);
        } catch (Throwable $e) {
            $recoveredId = $this->correlation->findQuoteIdByPlatformOperationId($operationId);
            if ($recoveredId !== null && $recoveredId !== '' && $recoveredId !== '0') {
                $this->idempotency->markCompleted(
                    $actorKey,
                    QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT,
                    $idemKey,
                    $recoveredId,
                );
                $this->tryMarkCartSubmitted($command);

                return new PersistedQuote(
                    quoteId: $recoveredId,
                    statusCode: $prepared->statusCode,
                    totalNet: $prepared->totalNet,
                    lineIds: [],
                );
            }
            $this->idempotency->markFailed(
                $actorKey,
                QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT,
                $idemKey,
                $e::class,
            );
            throw $e;
        }

        // LEGACY_COMMITTED — never markFailed after this point.
        try {
            $this->idempotency->markCompleted(
                $actorKey,
                QuoteWriteIdempotencyStore::OPERATION_PERSIST_DRAFT,
                $idemKey,
                $persisted->quoteId,
            );
        } catch (Throwable) {
            // Leave IN_PROGRESS; retry uses correlation probe.
        }

        $this->tryMarkCartSubmitted($command);

        return $persisted;
    }

    /**
     * @param list<string> $blockers
     * @param list<SaveQuoteLinePricingSnapshot> $snapshots
     */
    private function buildPreparation(
        SaveQuoteCommand $command,
        array $blockers,
        array $snapshots,
        bool $hasTbc,
        ?int $legacyCustomerId,
        ?int $raisedBy,
        bool $customerGenerated,
        string $currencyCode,
    ): SaveQuotePreparationResult {
        $blockers = array_values(array_unique($blockers));
        $draft = null;
        $preview = null;
        $totalNet = '0.00';
        $totalStatus = 'pending';
        $statusCode = $this->statusMapper->draftStatusCode($customerGenerated);
        $createdAt = $this->clock->now();
        $validUntil = $this->validity->validUntil($createdAt)->format('Y-m-d');
        $platformOperationId = $this->newUuid();

        if ($blockers === [] && $legacyCustomerId !== null && $raisedBy !== null && $snapshots !== []) {
            $draft = $this->draftMapper->toDraft(
                legacyCustomerId: $legacyCustomerId,
                currencyCode: $currencyCode,
                reference: $command->reference,
                customerGenerated: $customerGenerated,
                raisedByOperatorId: $raisedBy,
                recipientContactId: $command->recipientContactLegacyIdOverride,
                snapshots: $snapshots,
                platformOperationId: $platformOperationId,
            );
            $preview = $this->draftMapper->preview($draft);
            $totalNet = $draft->derivedTotalNet();
            $totalStatus = $hasTbc || bccomp($totalNet, '0', 2) === 0 ? 'pending' : 'resolved';
            $statusCode = $draft->statusCode;
            $validUntil = $draft->validUntil->format('Y-m-d');
        }

        $requestHash = hash('sha256', json_encode([
            'customerPublicId' => strtolower($command->customerPublicId),
            'cartPublicId' => strtolower($command->cartPublicId),
            'reference' => $command->reference,
            'contactPublicId' => $command->contactPublicId,
            'lines' => array_map(
                static fn (SaveQuoteLinePricingSnapshot $s): array => [
                    $s->variantPublicId,
                    $s->productCode,
                    $s->packCount,
                    $s->unitsOrLength,
                    $s->sellUnitPrice,
                    $s->lineNet,
                ],
                $snapshots,
            ),
        ], JSON_THROW_ON_ERROR));

        $fingerprint = hash('sha256', json_encode([
            'customerId' => $legacyCustomerId,
            'currency' => $currencyCode,
            'totalNet' => $totalNet,
            'lineCount' => count($snapshots),
            'products' => array_map(
                static fn (SaveQuoteLinePricingSnapshot $s): string => $s->productCode,
                $snapshots,
            ),
        ], JSON_THROW_ON_ERROR));

        return new SaveQuotePreparationResult(
            readyToPersist: $blockers === [] && $draft !== null,
            blockers: $blockers,
            draft: $draft,
            lineSnapshots: $snapshots,
            hasTbcLines: $hasTbc,
            currencyCode: $currencyCode,
            statusCode: $statusCode,
            validUntil: $validUntil,
            validityPolicyStatus: $this->validity->confirmationStatus(),
            mappedPreview: $preview,
            totalNet: $totalNet,
            totalStatus: $totalStatus,
            platformOperationId: $platformOperationId,
            requestHash: $requestHash,
            reconciliationFingerprint: $fingerprint,
        );
    }

    private function draftWithPlatformOperationId(PersistQuoteDraft $draft, string $platformOperationId): PersistQuoteDraft
    {
        if ($draft->platformOperationId === $platformOperationId) {
            return $draft;
        }

        return new PersistQuoteDraft(
            documentType: $draft->documentType,
            statusCode: $draft->statusCode,
            createdAt: $draft->createdAt,
            validUntil: $draft->validUntil,
            companyCode: $draft->companyCode,
            officeId: $draft->officeId,
            raisedByOperatorId: $draft->raisedByOperatorId,
            metricType: $draft->metricType,
            customerId: $draft->customerId,
            paymentTermsId: $draft->paymentTermsId,
            currencyCode: $draft->currencyCode,
            reference: $draft->reference,
            customerGenerated: $draft->customerGenerated,
            lines: $draft->lines,
            recipientContactId: $draft->recipientContactId,
            totalCostValue: $draft->totalCostValue,
            platformOperationId: $platformOperationId,
        );
    }

    /**
     * Cart failure after quote commit must not create a second quote on retry.
     * QuoteId association lives in Azure idempotency / MySQL correlation (cart has no quote FK yet).
     */
    private function tryMarkCartSubmitted(SaveQuoteCommand $command): void
    {
        try {
            $cart = $this->carts->getByPublicId(
                CartPublicId::fromString($command->cartPublicId),
                $command->actor->userId,
            );
            if ($cart === null || $cart->id() === null) {
                return;
            }
            if (!$cart->status()->isOpen()) {
                return;
            }
            $this->carts->markSubmitted($cart->id(), $command->actor->userId);
        } catch (Throwable) {
            // Swallow: idempotency COMPLETED + correlation already guarantee no duplicate quote.
        }
    }

    private function isPortalActor(\TriCab\Core\Identity\Application\DTO\AuthenticatedUser $actor): bool
    {
        foreach ($actor->roles as $roleCode) {
            if (ProtectedIdentityCatalog::isCustomerFacingRole(RoleCode::fromString($roleCode))) {
                return true;
            }
        }

        return false;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
