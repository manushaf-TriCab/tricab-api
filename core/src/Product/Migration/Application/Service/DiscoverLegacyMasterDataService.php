<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Application\DTO\LegacyMasterDataDiscoveryReport;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Live\LegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Read-only catalogue/master-data discovery. Does not create Products or seed masters.
 */
final class DiscoverLegacyMasterDataService
{
    private const PRODUCT_POST_TYPES = ['cable', 'accessory'];

    public function __construct(
        private readonly LiveLegacySourceConfig $config,
        private readonly ?LegacyWordPressRepository $wordpress,
        private readonly ?LegacyTcRepository $tc,
        private readonly LegacySqlStatementLogger $sqlLogger,
    ) {
    }

    /**
     * @param array<string, mixed> $azureSnapshot
     */
    public function execute(array $azureSnapshot = []): LegacyMasterDataDiscoveryReport
    {
        $started = microtime(true);
        $wpConnected = $this->wordpress !== null && $this->config->wordpressConfigured();
        $tcConnected = $this->tc !== null && $this->config->tcConfigured();

        $postTypes = $wpConnected ? $this->wordpress->discoverPostTypeCounts() : [];
        $productTypes = $this->inferProductPostTypes($postTypes);
        $taxonomies = $wpConnected ? $this->wordpress->discoverProductTaxonomies($productTypes) : [];
        $metaKeys = $wpConnected ? $this->wordpress->discoverProductMetaKeys($productTypes) : [];
        $wpStyles = $wpConnected ? $this->wordpress->discoverStyleCodes($productTypes) : [];
        $menus = $wpConnected ? $this->wordpress->discoverNavMenus() : [];
        $attachments = $wpConnected ? $this->wordpress->discoverProductAttachments($productTypes) : [];

        $tcTables = $tcConnected ? $this->tc->discoverProductRelatedTables() : [];
        $tproVsExt = $tcConnected ? $this->tc->discoverTproVsTproExt() : [];
        $variantCounts = $tcConnected ? $this->tc->discoverVariantCountsByStyle() : [];
        $specLabels = $tcConnected ? $this->tc->discoverSpecLabels() : [];
        $tcStyles = $tcConnected ? $this->tc->discoverStyleCodes() : [];

        $optionAudits = [];
        if ($tcConnected) {
            foreach ([
                ['tpro_ext', 'colour'],
                ['tpro_ext', 'sheath'],
                ['tpro_ext', 'conductor_type'],
                ['tpro_ext', 'armour'],
                ['tpro_ext', 'config_core'],
                ['cable_specs', 'core_con'],
            ] as [$table, $column]) {
                $rows = $this->tc->discoverColumnValueCounts($table, $column, 300);
                if ($rows !== []) {
                    $optionAudits[] = ['table' => $table, 'column' => $column, 'values' => $rows];
                }
            }
        }

        $join = $this->analyseStyleJoin($wpStyles, $tcStyles);
        $families = $this->proposeFamilies($taxonomies, $azureSnapshot['families'] ?? []);
        $categories = $this->proposeCategories($taxonomies, $azureSnapshot['categories'] ?? []);
        $series = $this->proposeSeries($wpStyles, $azureSnapshot['series'] ?? []);
        $specInventory = $this->proposeSpecifications($metaKeys, $tcTables, $specLabels, $azureSnapshot);
        $variantStats = $this->variantStatistics($variantCounts);
        $coverage = $this->coverage($join, $families, $categories, $series, $specInventory);

        $payload = [
            'wordpress' => [
                'postTypes' => $postTypes,
                'inferredProductPostTypes' => $productTypes,
                'taxonomies' => $taxonomies,
                'metaKeys' => $this->annotateMetaKeys($metaKeys),
                'styleCodes' => $wpStyles,
                'navMenus' => $menus,
                'attachments' => $attachments,
            ],
            'tc' => [
                'tables' => $tcTables,
                'tproVsTproExt' => $tproVsExt,
                'styleCodes' => $tcStyles,
                'variantCountsByStyle' => $variantCounts,
                'variantStatistics' => $variantStats,
                'specLabels' => $specLabels,
                'optionAudits' => $optionAudits,
            ],
            'join' => $join,
            'classification' => [
                'families' => $families,
                'categories' => $categories,
                'series' => $series,
            ],
            'catalogueNavigation' => [
                'legacyMenus' => $menus,
                'frontendCategories' => $azureSnapshot['frontendCategories'] ?? [],
                'existingTabs' => $azureSnapshot['catalogueTabs'] ?? [],
                'existingNodes' => $azureSnapshot['catalogueNodes'] ?? [],
                'note' => 'Family/Category/Series is internal classification. Catalogue navigation is a separate customer tree.',
            ],
            'specifications' => $specInventory,
            'azure' => [
                'products' => $azureSnapshot['products'] ?? [],
                'families' => $azureSnapshot['families'] ?? [],
                'categories' => $azureSnapshot['categories'] ?? [],
                'series' => $azureSnapshot['series'] ?? [],
                'specificationDefinitions' => $azureSnapshot['specificationDefinitions'] ?? [],
                'specificationGroups' => $azureSnapshot['specificationGroups'] ?? [],
                'units' => $azureSnapshot['units'] ?? [],
                'catalogueTabs' => $azureSnapshot['catalogueTabs'] ?? [],
                'catalogueNodes' => $azureSnapshot['catalogueNodes'] ?? [],
            ],
            'coverage' => $coverage,
            'migrationReadiness' => $this->readiness($join, $coverage),
            'proposedMappings' => [
                'families' => $families,
                'categories' => $categories,
                'series' => $series,
                'specifications' => $specInventory['definitions'] ?? [],
            ],
            'notes' => [
                'Discovery only. No Families, Categories, Series, definitions, options, or Products were created.',
                'Legacy MySQL was queried through LegacyReadOnlySqlGuard only.',
                'Proposed mappings are operator-reviewable and not applied.',
            ],
        ];

        $log = array_map(static fn ($e) => $e->toArray(), $this->sqlLogger->entries());

        return new LegacyMasterDataDiscoveryReport(
            generatedAtUtc: gmdate('Y-m-d\TH:i:s\Z'),
            wordpressConnected: $wpConnected,
            tcConnected: $tcConnected,
            payload: $payload,
            sqlLog: $log,
            queriesExecuted: count($log),
            executionSeconds: round(microtime(true) - $started, 3),
            readOnlyGuaranteed: true,
        );
    }

    /**
     * @param list<array{post_type: string, post_status: string, total: int}> $postTypes
     * @return list<string>
     */
    private function inferProductPostTypes(array $postTypes): array
    {
        $known = [];
        foreach ($postTypes as $row) {
            $type = strtolower((string) ($row['post_type'] ?? ''));
            if (in_array($type, ['cable', 'accessory', 'certificate', 'product', 'products'], true)) {
                $known[] = $type;
            }
        }
        $known = array_values(array_unique($known));

        return $known !== [] ? $known : self::PRODUCT_POST_TYPES;
    }

    /**
     * @param list<array<string, mixed>> $wpStyles
     * @param list<array<string, mixed>> $tcStyles
     * @return array<string, mixed>
     */
    private function analyseStyleJoin(array $wpStyles, array $tcStyles): array
    {
        $wpRaw = [];
        foreach ($wpStyles as $row) {
            $code = MigrationPayload::string($row['style_code'] ?? '');
            if ($code !== '') {
                $wpRaw['s:' . $code] = (int) ($row['product_count'] ?? 1);
            }
        }
        $tcRaw = [];
        foreach ($tcStyles as $row) {
            $code = MigrationPayload::string($row['style_code'] ?? '');
            if ($code !== '') {
                $tcRaw['s:' . $code] = true;
            }
        }

        $wpExact = [];
        foreach (array_keys($wpRaw) as $key) {
            $code = substr((string) $key, 2);
            $wpExact[strtoupper(trim($code))] = $code;
        }
        $tcExact = [];
        foreach (array_keys($tcRaw) as $key) {
            $code = substr((string) $key, 2);
            $tcExact[strtoupper(trim($code))] = $code;
        }

        $matched = array_values(array_intersect(array_keys($wpExact), array_keys($tcExact)));
        $wpOnly = array_values(array_diff(array_keys($wpExact), array_keys($tcExact)));
        $tcOnly = array_values(array_diff(array_keys($tcExact), array_keys($wpExact)));
        $caseOnly = [];
        foreach ($wpRaw as $key => $_) {
            $code = substr((string) $key, 2);
            foreach (array_keys($tcRaw) as $tcKey) {
                $tcCode = substr((string) $tcKey, 2);
                if ($code !== $tcCode && strcasecmp($code, $tcCode) === 0) {
                    $caseOnly[] = ['wordpress' => $code, 'tc' => $tcCode];
                }
            }
        }
        $whitespace = [];
        foreach ($wpRaw as $key => $_) {
            $code = substr((string) $key, 2);
            if ($code !== trim($code)) {
                $whitespace[] = $code;
            }
        }

        $wpCount = count($wpExact);
        $matchPct = $wpCount === 0 ? 0.0 : round(100 * count($matched) / $wpCount, 1);

        return [
            'wordpressStyleCount' => $wpCount,
            'tcStyleCount' => count($tcExact),
            'exactMatches' => count($matched),
            'wordpressOnly' => count($wpOnly),
            'tcOnly' => count($tcOnly),
            'matchPercent' => $matchPct,
            'caseOnlyDifferences' => $caseOnly,
            'whitespaceDifferences' => $whitespace,
            'wordpressOnlyCodes' => array_slice($wpOnly, 0, 100),
            'tcOnlyCodes' => array_slice($tcOnly, 0, 100),
            'proposedNormalization' => 'UPPER(TRIM(style_code)) for matching only; persist original values.',
            'joinKey' => 'style_code',
        ];
    }

    /**
     * @param list<array<string, mixed>> $taxonomies
     * @param list<array<string, mixed>> $existing
     * @return list<array<string, mixed>>
     */
    private function proposeFamilies(array $taxonomies, array $existing): array
    {
        $existingByName = [];
        foreach ($existing as $row) {
            $name = strtoupper(trim((string) ($row['name'] ?? $row['code'] ?? '')));
            if ($name !== '') {
                $existingByName[$name] = $row;
            }
        }
        $out = [];
        foreach ($taxonomies as $term) {
            if ((int) ($term['parent'] ?? 0) !== 0) {
                continue;
            }
            $name = MigrationPayload::string($term['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $key = strtoupper(trim($name));
            $match = $existingByName[$key] ?? null;
            $out[] = [
                'legacySource' => 'wordpress:' . MigrationPayload::string($term['taxonomy'] ?? 'category'),
                'legacyValue' => $name,
                'legacySlug' => MigrationPayload::string($term['slug'] ?? ''),
                'productCount' => (int) ($term['usage_count'] ?? 0),
                'proposedFamilyName' => $name,
                'proposedFamilyCode' => strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $name) ?? $name),
                'status' => $match !== null ? 'EXISTING_MATCH' : 'NEW_REQUIRED',
                'existingCode' => $match['code'] ?? null,
                'confidence' => $match !== null ? 'high' : 'medium',
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $taxonomies
     * @param list<array<string, mixed>> $existing
     * @return list<array<string, mixed>>
     */
    private function proposeCategories(array $taxonomies, array $existing): array
    {
        $existingByName = [];
        foreach ($existing as $row) {
            $name = strtoupper(trim((string) ($row['name'] ?? '')));
            if ($name !== '') {
                $existingByName[$name] = $row;
            }
        }
        $byId = [];
        foreach ($taxonomies as $term) {
            $byId[(int) ($term['term_id'] ?? 0)] = $term;
        }
        $out = [];
        foreach ($taxonomies as $term) {
            $parent = (int) ($term['parent'] ?? 0);
            if ($parent === 0) {
                continue;
            }
            $name = MigrationPayload::string($term['name'] ?? '');
            $parentName = MigrationPayload::string($byId[$parent]['name'] ?? '');
            $match = $existingByName[strtoupper(trim($name))] ?? null;
            $out[] = [
                'legacyCategory' => $name,
                'parentFamily' => $parentName !== '' ? $parentName : 'UNMAPPED',
                'usageCount' => (int) ($term['usage_count'] ?? 0),
                'legacySlug' => MigrationPayload::string($term['slug'] ?? ''),
                'status' => $match !== null ? 'EXISTING_MATCH' : 'NEW_REQUIRED',
                'existingCode' => $match['code'] ?? null,
                'proposedMapping' => $parentName !== '' ? $parentName . ' / ' . $name : $name,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $wpStyles
     * @param list<array<string, mixed>> $existing
     * @return list<array<string, mixed>>
     */
    private function proposeSeries(array $wpStyles, array $existing): array
    {
        $existingByCode = [];
        foreach ($existing as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? $row['name'] ?? '')));
            if ($code !== '') {
                $existingByCode[$code] = $row;
            }
        }
        $out = [];
        foreach ($wpStyles as $row) {
            $code = strtoupper(trim(MigrationPayload::string($row['style_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $match = $existingByCode[$code] ?? null;
            $out[] = [
                'legacySeries' => $code,
                'productCount' => (int) ($row['product_count'] ?? 0),
                'exampleStyleCodes' => $code,
                'status' => $match !== null ? 'EXISTING_MATCH' : 'NEW_REQUIRED',
                'existingCode' => $match['code'] ?? null,
                'recommendedMapping' => 'Treat WordPress style_code as Product Series candidate pending operator review.',
                'confidence' => $match !== null ? 'high' : 'medium',
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $metaKeys
     * @param list<array<string, mixed>> $tcTables
     * @param list<array<string, mixed>> $specLabels
     * @param array<string, mixed> $azureSnapshot
     * @return array<string, mixed>
     */
    private function proposeSpecifications(array $metaKeys, array $tcTables, array $specLabels, array $azureSnapshot): array
    {
        $definitions = $azureSnapshot['specificationDefinitions'] ?? [];
        $byCode = [];
        foreach ($definitions as $def) {
            $code = strtoupper((string) ($def['code'] ?? $def['name'] ?? ''));
            if ($code !== '') {
                $byCode[$code] = $def;
            }
        }
        $mapped = [];
        foreach ($this->annotateMetaKeys($metaKeys) as $meta) {
            $candidate = strtoupper((string) ($meta['candidateTarget'] ?? ''));
            $status = 'NEW_DEFINITION_REQUIRED';
            if (isset($byCode[$candidate])) {
                $status = 'EXISTING_DEFINITION';
            } elseif ($candidate === 'IGNORE') {
                $status = 'IGNORE_CANDIDATE';
            } elseif ($candidate === '') {
                $status = 'AMBIGUOUS';
            }
            $mapped[] = [
                'legacyField' => $meta['meta_key'],
                'sourceTable' => 'wp_postmeta',
                'usageCount' => $meta['product_count'],
                'exampleValues' => [$meta['example_min'] ?? null, $meta['example_max'] ?? null],
                'candidateDefinition' => $meta['candidateTarget'],
                'status' => $status,
            ];
        }

        $dFields = [];
        foreach ($specLabels as $row) {
            $dFields[] = $row;
        }

        $units = [];
        foreach ($azureSnapshot['units'] ?? [] as $unit) {
            $units[] = $unit;
        }

        return [
            'definitions' => $mapped,
            'specLabels' => $dFields,
            'tcTables' => $tcTables,
            'units' => $units,
            'matchedCount' => count(array_filter($mapped, static fn (array $r): bool => $r['status'] === 'EXISTING_DEFINITION')),
            'newRequiredCount' => count(array_filter($mapped, static fn (array $r): bool => $r['status'] === 'NEW_DEFINITION_REQUIRED')),
            'ambiguousCount' => count(array_filter($mapped, static fn (array $r): bool => $r['status'] === 'AMBIGUOUS')),
        ];
    }

    /**
     * @param list<array<string, mixed>> $metaKeys
     * @return list<array<string, mixed>>
     */
    private function annotateMetaKeys(array $metaKeys): array
    {
        $out = [];
        foreach ($metaKeys as $row) {
            $key = MigrationPayload::string($row['meta_key'] ?? '');
            $example = MigrationPayload::string($row['example_min'] ?? $row['example_max'] ?? '');
            $out[] = [
                'meta_key' => $key,
                'product_count' => (int) ($row['product_count'] ?? 0),
                'example_min' => $row['example_min'] ?? null,
                'example_max' => $row['example_max'] ?? null,
                'inferredType' => $this->inferType($example),
                'candidateTarget' => $this->candidateTarget($key),
            ];
        }

        return $out;
    }

    private function inferType(string $example): string
    {
        if ($example === '') {
            return 'unknown';
        }
        if (is_numeric($example)) {
            return 'number';
        }
        if (str_starts_with($example, 'a:') || str_starts_with($example, 's:')) {
            return 'serialized';
        }
        if (preg_match('/^\d+$/', $example) === 1) {
            return 'integer-or-attachment-id';
        }

        return 'text';
    }

    private function candidateTarget(string $key): string
    {
        $k = strtolower($key);

        return match (true) {
            $k === 'style_code' => 'SERIES_OR_IDENTITY',
            str_contains($k, 'voltage') => 'VOLTAGE',
            str_contains($k, 'temperatur') => 'TEMPERATURE',
            str_contains($k, 'construction') => 'CONSTRUCTION',
            str_contains($k, 'application') => 'APPLICATIONS',
            str_contains($k, 'standard') => 'STANDARDS',
            str_contains($k, 'approval') => 'APPROVALS',
            str_contains($k, 'jacket') => 'JACKET',
            str_contains($k, 'conductor') => 'CONDUCTOR',
            str_contains($k, 'braid') => 'BRAID',
            str_contains($k, 'colour') || str_contains($k, 'color') => 'CORE_COLOURS',
            str_contains($k, 'weight') => 'WEIGHT',
            str_contains($k, 'od') || str_contains($k, 'diameter') => 'OUTER_DIAMETER',
            $k === 'small_desc' || str_contains($k, 'description') => 'PRODUCT_DESCRIPTION',
            str_contains($k, 'image') || str_contains($k, 'thumb') => 'MEDIA',
            str_contains($k, 'cert') || str_contains($k, 'pdf') || str_contains($k, 'datasheet') => 'DOCUMENT',
            default => '',
        };
    }

    /**
     * @param list<array<string, mixed>> $variantCounts
     * @return array<string, mixed>
     */
    private function variantStatistics(array $variantCounts): array
    {
        $buckets = ['1' => 0, '2-10' => 0, '11-50' => 0, '>50' => 0];
        $max = 0;
        $totalVariants = 0;
        foreach ($variantCounts as $row) {
            $n = (int) ($row['variant_count'] ?? 0);
            $totalVariants += $n;
            $max = max($max, $n);
            if ($n <= 1) {
                ++$buckets['1'];
            } elseif ($n <= 10) {
                ++$buckets['2-10'];
            } elseif ($n <= 50) {
                ++$buckets['11-50'];
            } else {
                ++$buckets['>50'];
            }
        }

        return [
            'styles' => count($variantCounts),
            'totalVariants' => $totalVariants,
            'maximumVariants' => $max,
            'productsWith1Variant' => $buckets['1'],
            'productsWith2To10' => $buckets['2-10'],
            'productsWith11To50' => $buckets['11-50'],
            'productsWithMoreThan50' => $buckets['>50'],
        ];
    }

    /**
     * @param array<string, mixed> $join
     * @param list<array<string, mixed>> $families
     * @param list<array<string, mixed>> $categories
     * @param list<array<string, mixed>> $series
     * @param array<string, mixed> $specs
     * @return array<string, mixed>
     */
    private function coverage(array $join, array $families, array $categories, array $series, array $specs): array
    {
        $pct = static function (int $matched, int $total): float {
            return $total === 0 ? 0.0 : round(100 * $matched / $total, 1);
        };

        return [
            'productIdentity' => $join['matchPercent'] ?? 0,
            'familyMapping' => $pct(count(array_filter($families, static fn (array $r): bool => $r['status'] === 'EXISTING_MATCH')), count($families)),
            'categoryMapping' => $pct(count(array_filter($categories, static fn (array $r): bool => $r['status'] === 'EXISTING_MATCH')), count($categories)),
            'seriesMapping' => $pct(count(array_filter($series, static fn (array $r): bool => $r['status'] === 'EXISTING_MATCH')), count($series)),
            'specificationDefinition' => $pct((int) ($specs['matchedCount'] ?? 0), count($specs['definitions'] ?? [])),
        ];
    }

    /**
     * @param array<string, mixed> $join
     * @param array<string, mixed> $coverage
     * @return array<string, mixed>
     */
    private function readiness(array $join, array $coverage): array
    {
        $blockers = [];
        if (($join['wordpressOnly'] ?? 0) > 0) {
            $blockers[] = 'WordPress style codes with no tc match require review.';
        }
        if (($join['tcOnly'] ?? 0) > 0) {
            $blockers[] = 'tc styles with no WordPress product require review.';
        }
        if (($coverage['familyMapping'] ?? 0) < 100) {
            $blockers[] = 'Unmapped Families remain.';
        }
        if (($coverage['specificationDefinition'] ?? 0) < 100) {
            $blockers[] = 'Specification definitions are incomplete.';
        }

        return [
            'fullProductMigration' => $blockers === [] ? 'READY' : 'NOT READY',
            'blockingIssues' => $blockers,
        ];
    }
}
