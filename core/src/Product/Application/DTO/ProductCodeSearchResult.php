<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * One row of [product].[ProductCodeIndex], segments and all.
 *
 * The nine segments are carried separately rather than only as the assembled code
 * because the UI renders a segment-by-segment breakdown, and because a caller that
 * wants "the same cable one size up" needs the parts, not the string.
 *
 * Catalogue fields are nullable by design: only about 4,600 of the ~32,000 indexed
 * codes correspond to a loaded ProductVariant, and 94 of the 130 code-bearing
 * products have no slug. A code with no slug is still a real, sellable code.
 */
final readonly class ProductCodeSearchResult
{
    public function __construct(
        public string $codeValue,
        public string $styleCode,
        public ?string $styleName,
        public ?string $styleSuffix,
        public ?string $jacketTypeCode,
        public ?string $coreColourConfig,
        public ?string $braidArmourScreen,
        public ?string $conductorCode,
        public ?int $coreCount,
        public ?string $coreTypeCode,
        public ?string $crossSectionRaw,
        public ?string $crossSectionUnit,
        public ?float $crossSectionMm2,
        public ?float $crossSectionNominalMm2,
        public ?int $setCores,
        public ?string $jacketColour,
        public ?string $stockStatus,
        public bool $isCurrent,
        public ?float $weightKgPerKm,
        public ?string $description,
        public ?string $productName = null,
        public ?string $productSlug = null,
        public ?string $variantPublicId = null,
        public ?string $variantCode = null,
        public ?int $matchTier = null,
    ) {
    }
}
