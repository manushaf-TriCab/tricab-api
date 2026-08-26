<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Phase 13.2/13.3 zero-write resolution result for one legacy price row.
 */
final readonly class PriceImportResolution
{
    public const IDENTITY_EXACT = 'EXACT';
    public const IDENTITY_MASK = 'MASK';
    public const IDENTITY_EXPLICIT = 'EXPLICIT';
    public const IDENTITY_UNRESOLVED = 'UNRESOLVED';
    public const IDENTITY_AMBIGUOUS = 'AMBIGUOUS';

    public const READY = 'READY';
    public const REVIEW = 'REVIEW';
    public const BLOCKED = 'BLOCKED';

    /**
     * @param list<string> $reasons
     * @param list<string> $productVariantIds
     */
    public function __construct(
        public string $sourceRowId,
        public string $identityType,
        public ?string $productId,
        public ?string $productVariantId,
        /** @var list<string> */
        public array $productVariantIds,
        public ?string $priceListCode,
        public ?string $priceListId,
        public ?string $currencyCode,
        public ?string $currencyId,
        public string $baseCost,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
        public string $sourceExternalKey,
        public string $historyDecision,
        public string $zeroCostDecision,
        public string $duplicateDecision,
        public string $readiness,
        public array $reasons,
    ) {
    }
}
