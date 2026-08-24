<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Contract;

/**
 * Strictly read-only tc product inspection API. No save/update/delete.
 */
interface LegacyTcRepository
{
    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findVariants(array $filter = []): array;

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findSpecifications(array $filter = []): array;

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findCodes(array $filter = []): array;

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findAccessories(array $filter = []): array;

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findDescriptions(array $filter = []): array;

    /**
     * @return array{tables: list<string>, columns: array<string, list<string>>, rowCounts: array<string, int>}
     */
    public function inspectSchema(): array;

    /**
     * Read-only latest tc.price.cost for an item code and debtor company (dbco).
     *
     * @return array{cost: string, lastUpdatedUtc: ?string}|null
     */
    public function findLatestBaseCost(string $itemCode, int $debtorCompanyCode): ?array;

    /**
     * Aggregate-only verification for a legacy tc.price dbco context.
     *
     * @return array{dbco: int, priceRowCount: int, distinctCodeCount: int}|null
     */
    public function aggregatePriceContextByDbco(int $dbco): ?array;

    /**
     * Inspect pricing code identity and latest revision metadata for a dbco context.
     * Multiple historical rows for the same code+dbco represent one pricing identity.
     *
     * @return array{
     *   exists: bool,
     *   revisionCount: int,
     *   latestDc: ?string,
     *   latestCost: ?string,
     *   zeroCost: bool,
     *   duplicateLatestDc: bool,
     * }|null null when price table unavailable
     */
    public function inspectPricingCode(string $code, int $dbco): ?array;

    /**
     * Batch inspect pricing codes for the same dbco.
     *
     * @param list<string> $codes
     * @return array<string, array{
     *   exists: bool,
     *   revisionCount: int,
     *   latestDc: ?string,
     *   latestCost: ?string,
     *   zeroCost: bool,
     *   duplicateLatestDc: bool,
     * }>
     */
    public function inspectPricingCodes(array $codes, int $dbco): array;

    /**
     * @return list<array{table: string, rowCount: int, columns: list<string>, primaryKey: list<string>}>
     */
    public function discoverProductRelatedTables(): array;

    /**
     * @return array<string, mixed>
     */
    public function discoverTproVsTproExt(): array;

    /**
     * @return list<array{style_code: string, variant_count: int, example_codes: string}>
     */
    public function discoverVariantCountsByStyle(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function discoverSpecLabels(): array;

    /**
     * @return list<array{style_code: string, source: string}>
     */
    public function discoverStyleCodes(): array;

    /**
     * Distinct values for a known product-spec column (aggregated, not per-row dump).
     *
     * @return list<array{value: string, usage_count: int}>
     */
    public function discoverColumnValueCounts(string $table, string $column, int $limit = 200): array;

    /**
     * Numeric / opaque cable_style values needing interpretation.
     *
     * @return list<array<string, mixed>>
     */
    public function discoverNumericCableStyles(): array;

    /**
     * Accessory identity join candidates (batched aggregates).
     *
     * @return array<string, mixed>
     */
    public function discoverAccessoryIdentitySummary(): array;

    /**
     * Style-specific d1–d7 / v1–v4 label mappings from spec_labels + sample values.
     *
     * @return list<array<string, mixed>>
     */
    public function discoverDvFieldMappings(int $limit = 5000): array;

    /**
     * Evidence whether accessory_specs fields vary within a style (PRODUCT vs VARIANT scope).
     *
     * @return list<array<string, mixed>>
     */
    public function discoverAccessorySpecScopeEvidence(): array;

    /**
     * Classify tpro rows vs tpro_ext for ProductVariant migration intent.
     *
     * @return array<string, mixed>
     */
    public function discoverTproExclusionSummary(): array;

    /**
     * Aggregate variant readiness signals from tpro_ext (no writes).
     *
     * @return array<string, mixed>
     */
    public function discoverVariantReadinessSummary(): array;
}
