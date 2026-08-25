<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * One aggregated style-code observation from a legacy source.
 * sourceOccurrences is the number of source rows contributing to this raw value.
 */
final readonly class LegacyStyleOccurrence
{
    /**
     * @param list<string> $sampleProducts
     */
    public function __construct(
        public string $source,
        public string $tableEntity,
        public string $field,
        public string $rawStyleCode,
        public int $sourceOccurrences,
        public int $productCount,
        public array $sampleProducts = [],
        public ?string $familyEvidence = null,
    ) {
    }
}
