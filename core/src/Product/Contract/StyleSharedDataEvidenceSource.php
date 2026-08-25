<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

/**
 * Bulk read-only evidence for Style family shared-data generalisation dry-run.
 *
 * @phpstan-type FamilyRow array{
 *   parentStyleCode: string,
 *   parentStylePublicId: string,
 *   childStyleCode: string,
 *   childStylePublicId: string
 * }
 * @phpstan-type ProductRow array{
 *   styleCode: string,
 *   productPublicId: string,
 *   productCode: string,
 *   productName: string,
 *   statusCode: string,
 *   isActive: bool
 * }
 * @phpstan-type SpecRow array{
 *   productCode: string,
 *   definitionCode: string,
 *   definitionName: string,
 *   displayValue: string
 * }
 * @phpstan-type MediaRow array{
 *   productCode: string,
 *   storagePath: string,
 *   roleCode: ?string,
 *   mediaTypeCode: string
 * }
 * @phpstan-type RegionRow array{
 *   productCode: string,
 *   regionCode: string
 * }
 * @phpstan-type ResourceCountRow array{
 *   productCode: string,
 *   resourceType: string,
 *   count: int
 * }
 */
interface StyleSharedDataEvidenceSource
{
    /** @return list<FamilyRow> */
    public function listPersistedFamilies(): array;

    /** @return list<ProductRow> */
    public function listStyleAssignments(): array;

    /**
     * @param list<string> $productCodes internal codes
     * @return list<SpecRow>
     */
    public function loadSpecificationsForProducts(array $productCodes): array;

    /**
     * @param list<string> $productCodes
     * @return list<MediaRow>
     */
    public function loadMediaForProducts(array $productCodes): array;

    /**
     * @param list<string> $productCodes
     * @return list<RegionRow>
     */
    public function loadRegionsForProducts(array $productCodes): array;

    /**
     * @param list<string> $productCodes
     * @return list<ResourceCountRow> resourceType: documents|spin360|models3d|ar
     */
    public function loadResourceCountsForProducts(array $productCodes): array;

    /** @return array<string, mixed> */
    public function loadEnvironmentSafety(): array;
}
