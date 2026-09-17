<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

/**
 * Server-resolved line snapshot after forced reprice (includes cost for persistence).
 */
final readonly class SaveQuoteLinePricingSnapshot
{
    public function __construct(
        public string $variantPublicId,
        public string $productCode,
        public int $categoryType,
        public int $packCount,
        public string $unitsOrLength,
        public string $sellUnitPrice,
        public string $lineNet,
        public string $costUnitPrice,
        public string $costValue,
        public ?string $marginPercent,
        public string $pricingStatus,
        public ?string $marginSource,
        public ?int $colourId = null,
        public ?int $packMatrixId = null,
        public ?string $reference = null,
    ) {
    }

    public function isTbc(): bool
    {
        return strtoupper($this->pricingStatus) === 'TBC'
            || $this->sellUnitPrice === ''
            || bccomp($this->sellUnitPrice, '0', 6) === 0
            || bccomp($this->lineNet, '0', 2) === 0;
    }
}
