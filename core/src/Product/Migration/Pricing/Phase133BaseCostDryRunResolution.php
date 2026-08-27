<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Phase 13.3 zero-write dry-run resolution for one logical target candidate.
 */
final readonly class Phase133BaseCostDryRunResolution
{
    public const READY = 'READY';
    public const REVIEW = 'REVIEW';
    public const BLOCKED = 'BLOCKED';
    public const SKIPPED = 'SKIPPED';

    public const REF_DATABASE_ID = 'DATABASE_ID';
    public const REF_CONTRACT_ONLY = 'CONTRACT_ONLY';

    public const EXISTING_CREATE = 'CREATE_CANDIDATE';
    public const EXISTING_NO_CHANGE = 'NO_CHANGE';
    public const EXISTING_UPDATE = 'UPDATE_OR_REVISION_CANDIDATE';
    public const EXISTING_MANUAL = 'MANUAL_DATA_CONFLICT';
    public const EXISTING_NONE = 'NONE';

    /**
     * @param list<string> $sourceRowIds
     * @param list<string> $productVariantIds
     * @param list<string> $reasons
     * @param list<string> $trace
     */
    public function __construct(
        public array $sourceRowIds,
        public string $legacyCode,
        public string $normalizedCode,
        public int $dbco,
        public string $identityType,
        public ?string $productId,
        public ?string $productVariantId,
        public array $productVariantIds,
        public ?string $priceListCode,
        public ?string $priceListId,
        public string $referenceResolution,
        public ?string $currencyCode,
        public ?string $currencyId,
        public string $baseCost,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
        public string $duplicateDecision,
        public string $precedenceDecision,
        public string $collisionDecision,
        public string $existingTargetDecision,
        public string $zeroCostDecision,
        public string $historyDecision,
        public string $readiness,
        public array $reasons,
        public array $trace,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceRowIds' => $this->sourceRowIds,
            'legacyCode' => $this->legacyCode,
            'normalizedCode' => $this->normalizedCode,
            'dbco' => $this->dbco,
            'identityType' => $this->identityType,
            'productId' => $this->productId,
            'productVariantId' => $this->productVariantId,
            'productVariantIds' => $this->productVariantIds,
            'priceListCode' => $this->priceListCode,
            'priceListId' => $this->priceListId,
            'referenceResolution' => $this->referenceResolution,
            'currencyCode' => $this->currencyCode,
            'currencyId' => $this->currencyId,
            'baseCost' => $this->baseCost,
            'effectiveFrom' => $this->effectiveFrom,
            'effectiveTo' => $this->effectiveTo,
            'duplicateDecision' => $this->duplicateDecision,
            'precedenceDecision' => $this->precedenceDecision,
            'collisionDecision' => $this->collisionDecision,
            'existingTargetDecision' => $this->existingTargetDecision,
            'zeroCostDecision' => $this->zeroCostDecision,
            'historyDecision' => $this->historyDecision,
            'readiness' => $this->readiness,
            'reasons' => $this->reasons,
            'trace' => $this->trace,
        ];
    }
}
