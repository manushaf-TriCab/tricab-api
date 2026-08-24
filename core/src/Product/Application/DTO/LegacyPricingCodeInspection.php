<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Read-only inspection of a legacy tc.price code within a dbco context.
 * Multiple historical rows for the same code+dbco represent one pricing identity.
 */
final readonly class LegacyPricingCodeInspection
{
    public function __construct(
        public bool $exists,
        public int $revisionCount,
        public ?string $latestDc,
        public ?string $latestCost,
        public bool $zeroCost,
        public bool $duplicateLatestDc,
        public string $priceDataStatus,
    ) {
    }

    /**
     * @param array{
     *   exists: bool,
     *   revisionCount: int,
     *   latestDc: ?string,
     *   latestCost: ?string,
     *   zeroCost: bool,
     *   duplicateLatestDc: bool,
     * } $row
     */
    public static function fromLegacyRow(array $row): self
    {
        $priceDataStatus = \TriCab\Core\Product\Domain\ValueObject\LegacyPricingPriceDataStatus::NOT_FOUND;
        if ($row['exists']) {
            if ($row['duplicateLatestDc']) {
                $priceDataStatus = \TriCab\Core\Product\Domain\ValueObject\LegacyPricingPriceDataStatus::PRICE_REVISION_AMBIGUOUS;
            } elseif ($row['zeroCost']) {
                $priceDataStatus = \TriCab\Core\Product\Domain\ValueObject\LegacyPricingPriceDataStatus::ZERO_COST;
            } else {
                $priceDataStatus = \TriCab\Core\Product\Domain\ValueObject\LegacyPricingPriceDataStatus::AVAILABLE;
            }
        }

        return new self(
            exists: $row['exists'],
            revisionCount: $row['revisionCount'],
            latestDc: $row['latestDc'],
            latestCost: $row['latestCost'],
            zeroCost: $row['zeroCost'],
            duplicateLatestDc: $row['duplicateLatestDc'],
            priceDataStatus: $priceDataStatus,
        );
    }
}
