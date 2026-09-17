<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Command;

/**
 * Server-derived quote line. Pricing must already be resolved by application services.
 *
 * @phpstan-type ExtendedList list<PersistQuoteDraftExtendedLine>
 */
final readonly class PersistQuoteDraftLine
{
    /**
     * @param list<PersistQuoteDraftExtendedLine> $extended
     */
    public function __construct(
        public string $productCode,
        public int $categoryType,
        public string $packCount,
        public string $unitsOrLength,
        public string $sellUnitPrice,
        public string $lineNet,
        public string $costUnitPrice,
        public string $costValue,
        public ?int $colourId = null,
        /** Legacy tqoti.pm = pack-matrix descript id (e.g. 1501), NOT margin %. */
        public ?int $packMatrixId = null,
        public ?string $reference = null,
        public array $extended = [],
    ) {
    }
}
