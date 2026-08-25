<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

/**
 * Read-only Azure product evidence for style-family scoring.
 * Never writes classification, specifications, or media.
 */
interface ProductStyleFamilyEvidenceSource
{
    /**
     * @param list<string> $productPublicIds
     * @return array<string, array{
     *   familyCode: ?string,
     *   familyName: ?string,
     *   categoryCode: ?string,
     *   categoryName: ?string,
     *   seriesCode: ?string,
     *   seriesName: ?string,
     *   specificationDefinitionIds: list<string>,
     *   specificationFingerprints: array<string, string>,
     *   mediaKeys: list<string>
     * }>
     */
    public function load(array $productPublicIds): array;
}
