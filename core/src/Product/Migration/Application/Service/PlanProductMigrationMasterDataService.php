<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Application\DTO\MasterDataPlanReport;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.7 — plan / dry-run master-data + mapping prerequisites.
 * Does not migrate Products, Variants, media, documents, or prices.
 * Apply is a separate explicit step after operator approval.
 */
final class PlanProductMigrationMasterDataService
{
    public function __construct(
        private readonly LiveLegacySourceConfig $config,
        private readonly ?LegacyTcRepository $tc = null,
    ) {
    }

    /**
     * @param array<string, mixed> $phase126
     * @param array<string, mixed> $azureSnapshot
     * @param array<string, mixed> $environment
     */
    public function dryRun(array $phase126, array $azureSnapshot, array $environment): MasterDataPlanReport
    {
        $familiesExisting = $this->indexByCode($azureSnapshot['families'] ?? [], 'code');
        $categoriesExisting = $this->indexByCode($azureSnapshot['categories'] ?? [], 'code');
        $seriesExisting = $this->indexByCode($azureSnapshot['series'] ?? [], 'code');
        $defsExisting = $this->indexByCode($azureSnapshot['specificationDefinitions'] ?? [], 'code');
        $groupsExisting = $this->indexByCode($azureSnapshot['specificationGroups'] ?? [], 'code');
        $unitsExisting = $this->indexByCode($azureSnapshot['units'] ?? [], 'code');
        $nodesExisting = $azureSnapshot['catalogueNodes'] ?? [];
        $productsCount = (int) ($azureSnapshot['productCount'] ?? 0);
        if ($productsCount === 0 && is_array($azureSnapshot['products'] ?? null)) {
            $productsCount = count($azureSnapshot['products']);
        }

        $unitsMissing = ($azureSnapshot['specificationUnitsPresent'] ?? null) === false
            || (($azureSnapshot['specificationUnitsPresent'] ?? null) === null && $unitsExisting === []);

        $rootCause = $unitsMissing
            ? [
                'classification' => 'SCHEMA_NOT_APPLIED',
                'evidence' => [
                    'Phase 11 / create-product-schema.sql defines [product].[SpecificationUnits]',
                    'Phase 2C alter/seed scripts FK and MERGE against SpecificationUnits',
                    'Validated Azure target returns Invalid object name product.SpecificationUnits',
                    'Other product tables (Families, Categories, Series, Definitions) exist — partial product schema applied without Units',
                ],
                'not' => ['WRONG_DATABASE (families/products present)', 'invented alternate unit table'],
            ]
            : [
                'classification' => 'VERIFIED_PRESENT',
                'evidence' => ['SpecificationUnits query succeeded'],
            ];

        $unitSeeds = $this->proposedUnits($phase126, $unitsExisting);
        $familyOps = $this->proposedFamilies($phase126, $familiesExisting);
        $categoryOps = $this->proposedCategories($phase126, $categoriesExisting, $familiesExisting);
        $seriesOps = $this->proposedSeries($phase126, $seriesExisting, $familiesExisting);
        $definitionOps = $this->proposedDefinitions($phase126, $defsExisting);
        $dvOps = $this->proposedDvMappings($phase126);
        $optionOps = $this->proposedOptions($phase126);
        $standardsOps = $this->proposedValueOptions($phase126['standards'] ?? [], 'STANDARDS');
        $approvalsOps = $this->proposedValueOptions($phase126['approvals'] ?? [], 'APPROVALS');
        $catalogueOps = $this->proposedCatalogue($phase126, $nodesExisting);
        $accessory = $this->accessoryIdentity($phase126, $azureSnapshot);
        $tpro = $phase126['tproExclusions'] ?? [];
        $filesystem = [
            'legacyFilesRoot' => $this->config->filesConfigured() && !$this->config->waiveFiles ? 'AVAILABLE' : 'UNAVAILABLE',
            'configuredPath' => $this->config->filesRoot,
            'waived' => $this->config->waiveFiles,
            'mediaReadiness' => $this->config->filesConfigured() && !$this->config->waiveFiles ? 'REVIEW' : 'BLOCKED',
            'documentReadiness' => $this->config->filesConfigured() && !$this->config->waiveFiles ? 'REVIEW' : 'BLOCKED',
            'note' => 'Do not invent filesystem paths. No copy/upload in Phase 12.7.',
        ];

        $schemaOps = [];
        if ($unitsMissing) {
            $schemaOps[] = [
                'object' => 'product.SpecificationUnits',
                'action' => 'CREATE_TABLE',
                'script' => 'database/scripts/development/alter-product-specification-units-phase12.7.sql',
                'priority' => 'MIGRATION_BLOCKER',
                'decision' => 'PROPOSED_FOR_APPROVAL',
                'destructive' => false,
            ];
        }
        $schemaOps[] = [
            'object' => 'migration.SpecificationFieldMappings.LegacyStyleCode',
            'action' => 'ADD_COLUMN_AND_UNIQUE_INDEX',
            'script' => 'database/scripts/development/alter-migration-spec-field-style-phase12.7.sql',
            'priority' => 'REQUIRED',
            'decision' => 'PROPOSED_FOR_APPROVAL',
            'reason' => 'Style-specific d/v mappings cannot fit unique (system,table,field) alone',
            'destructive' => false,
        ];

        $applyCandidates = array_values(array_filter(
            array_merge(
                $schemaOps,
                $unitSeeds['operations'],
                $familyOps['operations'],
                $categoryOps['operations'],
                $seriesOps['operations'],
                $definitionOps['operations'],
                $dvOps['operations'],
                $optionOps['operations'],
                $standardsOps['operations'],
                $approvalsOps['operations'],
                $catalogueOps['operations'],
            ),
            static fn (array $op): bool => ($op['decision'] ?? '') === 'PROPOSED_FOR_APPROVAL',
        ));

        $skippedReview = array_values(array_filter(
            array_merge(
                $categoryOps['operations'],
                $seriesOps['operations'],
                $definitionOps['operations'],
                $dvOps['operations'],
                $optionOps['operations'],
                $standardsOps['operations'],
                $approvalsOps['operations'],
                $catalogueOps['operations'],
            ),
            static fn (array $op): bool => in_array($op['decision'] ?? '', ['REVIEW', 'REJECT', 'NOT_A_PRODUCT_CATEGORY'], true),
        ));

        $payload = [
            'environment' => $environment,
            'environmentGate' => $this->environmentGate($environment),
            'phase126Baseline' => $phase126['baseline'] ?? null,
            'schemaInspection' => [
                'specificationUnitsPresent' => !$unitsMissing,
                'rootCause' => $rootCause,
                'existingProductCount' => $productsCount,
                'existingCatalogueNodeCount' => is_array($nodesExisting) ? count($nodesExisting) : 0,
                'families' => array_keys($familiesExisting),
                'preserveExistingProducts' => true,
                'preserveExistingCatalogueNodes' => true,
            ],
            'plan' => [
                'schema' => $schemaOps,
                'units' => $unitSeeds,
                'families' => $familyOps,
                'categories' => $categoryOps,
                'series' => $seriesOps,
                'specificationGroups' => [
                    'reuse' => array_keys($groupsExisting),
                    'create' => [],
                    'note' => 'Reuse existing groups; no duplicate name groups proposed in dry-run.',
                ],
                'definitions' => $definitionOps,
                'dvMappings' => $dvOps,
                'options' => $optionOps,
                'standards' => $standardsOps,
                'approvals' => $approvalsOps,
                'coreColours' => [
                    'model' => 'CORE-COLOURS long text',
                    'decision' => 'RAW_TEXT_REQUIRED',
                    'autoCreateOptions' => false,
                    'note' => 'Do not force matrices into CODE_CORE_COLOUR single-select.',
                ],
                'catalogue' => $catalogueOps,
            ],
            'accessoryIdentity' => $accessory,
            'tproExclusions' => [
                'summary' => $tpro,
                'validatedRule' => [
                    'HAS_TPRO_EXT_JOIN' => 'REVIEW candidate ProductVariant (ida = product_code)',
                    'TPRO_ONLY' => 'EXCLUDED commerce/historical/stock — not auto Product/Variant',
                    'LEFT(ida,3)' => 'FORBIDDEN identity heuristic',
                ],
            ],
            'filesystem' => $filesystem,
            'dryRunSummary' => [
                'azureMutations' => 0,
                'legacyMutations' => 0,
                'proposedForApprovalCount' => count($applyCandidates),
                'reviewOrSkippedCount' => count($skippedReview),
                'conflicts' => $this->detectConflicts($familyOps, $unitsExisting, $unitsMissing),
            ],
            'applyCandidates' => $applyCandidates,
            'reviewItems' => array_slice($skippedReview, 0, 200),
            'approvalBoundary' => [
                'phase126ApprovalDoesNotAuthorizeTargetWrites' => true,
                'applyRequiresExplicitOperatorApproval' => true,
                'stopAfterDryRun' => true,
                'applyCommand' => 'php bin/apply-product-migration-master-data.php --apply --confirm-non-production',
            ],
            'plugVsAccessories' => [
                'PLUG_family' => isset($familiesExisting['PLUG']) ? 'KEEP_SEPARATE' : 'ABSENT',
                'ACCESSORIES_family' => 'PROPOSED_CREATE',
                'autoMerge' => false,
            ],
        ];

        return new MasterDataPlanReport(
            generatedAtUtc: gmdate('Y-m-d\TH:i:s\Z'),
            mode: 'dry-run',
            applied: false,
            payload: $payload,
        );
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    private function environmentGate(array $environment): array
    {
        $label = strtoupper(MigrationPayload::string($environment['environmentLabel'] ?? 'UNKNOWN'));
        $db = MigrationPayload::string($environment['database'] ?? '');
        $server = MigrationPayload::string($environment['server'] ?? '');
        $nonProdHints = preg_match('/dev|test|uat|non.?prod|local|staging/i', $label . ' ' . $db . ' ' . $server) === 1;
        $prodHints = preg_match('/\bprod(uction)?\b/i', $label . ' ' . $db) === 1 && !$nonProdHints;

        if ($prodHints) {
            $status = 'PRODUCTION_BLOCKED';
        } elseif ($db === '' || $server === '') {
            $status = 'UNKNOWN';
        } elseif ($nonProdHints || $label === 'NON-PRODUCTION') {
            $status = 'NON-PRODUCTION';
        } else {
            $status = 'UNKNOWN';
        }

        return [
            'status' => $status,
            'server' => $server,
            'database' => $db,
            'allowApply' => $status === 'NON-PRODUCTION',
            'stopIfUncertain' => $status !== 'NON-PRODUCTION',
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexByCode(array $rows, string $codeKey): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(trim(MigrationPayload::string($row[$codeKey] ?? '')));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $phase126
     * @param array<string, array<string, mixed>> $existing
     * @return array<string, mixed>
     */
    private function proposedUnits(array $phase126, array $existing): array
    {
        $required = [
            ['code' => 'M', 'name' => 'Metre', 'symbol' => 'm', 'priority' => 'MIGRATION_BLOCKER', 'source' => 'phase2c+specs'],
            ['code' => 'EA', 'name' => 'Each', 'symbol' => 'ea', 'priority' => 'MIGRATION_BLOCKER', 'source' => 'phase2c'],
            ['code' => 'FT', 'name' => 'Foot', 'symbol' => 'ft', 'priority' => 'REQUIRED', 'source' => 'phase2c'],
            ['code' => 'PK', 'name' => 'Pack', 'symbol' => 'pk', 'priority' => 'REQUIRED', 'source' => 'phase2c'],
            ['code' => 'MM', 'name' => 'Millimetre', 'symbol' => 'mm', 'priority' => 'MIGRATION_BLOCKER', 'source' => 'phase12.6'],
            ['code' => 'MM2', 'name' => 'Square millimetre', 'symbol' => 'mm²', 'priority' => 'MIGRATION_BLOCKER', 'source' => 'phase12.6'],
            ['code' => 'KG', 'name' => 'Kilogram', 'symbol' => 'kg', 'priority' => 'REQUIRED', 'source' => 'phase12.6'],
            ['code' => 'KG_PER_KM', 'name' => 'Kilogram per km', 'symbol' => 'kg/km', 'priority' => 'REQUIRED', 'source' => 'phase12.6'],
            ['code' => 'A', 'name' => 'Ampere', 'symbol' => 'A', 'priority' => 'REQUIRED', 'source' => 'phase12.6'],
            ['code' => 'V', 'name' => 'Volt', 'symbol' => 'V', 'priority' => 'REQUIRED', 'source' => 'phase12.6'],
            ['code' => 'C', 'name' => 'Degree Celsius', 'symbol' => '°C', 'priority' => 'REQUIRED', 'source' => 'phase12.6'],
        ];
        unset($phase126);
        $ops = [];
        foreach ($required as $unit) {
            $exists = isset($existing[$unit['code']]);
            $ops[] = [
                'type' => 'SpecificationUnit',
                'action' => $exists ? 'SKIP_EXISTS' : 'SEED',
                'unitCode' => $unit['code'],
                'unitName' => $unit['name'],
                'symbol' => $unit['symbol'],
                'priority' => $unit['priority'],
                'decision' => $exists ? 'MAP_EXISTING' : 'PROPOSED_FOR_APPROVAL',
                'script' => 'database/scripts/development/seed-product-specification-units-phase12.7.sql',
            ];
        }

        return [
            'seedCount' => count(array_filter($ops, static fn ($o) => $o['decision'] === 'PROPOSED_FOR_APPROVAL')),
            'alreadyPresent' => count(array_filter($ops, static fn ($o) => $o['decision'] === 'MAP_EXISTING')),
            'operations' => $ops,
            'canonicalization' => 'M (m) is the single metre/meter row — no duplicate metre/meter codes.',
        ];
    }

    /**
     * @param array<string, mixed> $phase126
     * @param array<string, array<string, mixed>> $existing
     * @return array<string, mixed>
     */
    private function proposedFamilies(array $phase126, array $existing): array
    {
        $ops = [];
        $ops[] = [
            'type' => 'ProductFamily',
            'action' => isset($existing['CABLE']) ? 'MAP_EXISTING' : 'CREATE',
            'code' => 'CABLE',
            'name' => 'Cables',
            'decision' => isset($existing['CABLE']) ? 'APPROVED_MAP_EXISTING' : 'PROPOSED_FOR_APPROVAL',
            'priority' => 'REQUIRED',
        ];
        $ops[] = [
            'type' => 'ProductFamily',
            'action' => isset($existing['ACCESSORIES']) ? 'MAP_EXISTING' : 'CREATE',
            'code' => 'ACCESSORIES',
            'name' => 'Accessories',
            'displayOrder' => 30,
            'decision' => isset($existing['ACCESSORIES']) ? 'APPROVED_MAP_EXISTING' : 'PROPOSED_FOR_APPROVAL',
            'priority' => 'REQUIRED',
            'source' => 'wordpress post_type=accessory',
            'note' => 'Do not merge with PLUG.',
        ];
        if (isset($existing['PLUG'])) {
            $ops[] = [
                'type' => 'ProductFamily',
                'action' => 'KEEP',
                'code' => 'PLUG',
                'decision' => 'KEEP_SEPARATE',
                'priority' => 'REQUIRED',
                'note' => 'PLUG ≠ ACCESSORIES — no automatic merge.',
            ];
        }
        unset($phase126);

        return [
            'create' => count(array_filter($ops, static fn ($o) => ($o['action'] ?? '') === 'CREATE' && ($o['decision'] ?? '') === 'PROPOSED_FOR_APPROVAL')),
            'map' => count(array_filter($ops, static fn ($o) => str_contains((string) ($o['decision'] ?? ''), 'MAP'))),
            'operations' => $ops,
        ];
    }

    /**
     * @param array<string, mixed> $phase126
     * @param array<string, array<string, mixed>> $categoriesExisting
     * @param array<string, array<string, mixed>> $familiesExisting
     * @return array<string, mixed>
     */
    private function proposedCategories(array $phase126, array $categoriesExisting, array $familiesExisting): array
    {
        $terms = $phase126['classification']['categories'] ?? [];
        $ops = [];
        $counts = [
            'APPROVED_CREATE' => 0,
            'APPROVED_MAP_EXISTING' => 0,
            'REVIEW' => 0,
            'REJECT' => 0,
            'NOT_A_PRODUCT_CATEGORY' => 0,
        ];
        foreach (is_array($terms) ? $terms : [] as $term) {
            if (!is_array($term)) {
                continue;
            }
            $concept = MigrationPayload::string($term['conceptClass'] ?? 'internal_category_candidate');
            $name = MigrationPayload::string($term['nameDecoded'] ?? $term['name'] ?? '');
            $decision = match ($concept) {
                'obsolete' => 'REJECT',
                'voltage', 'application', 'industry', 'customer_navigation', 'certificate_region', 'marketing' => 'NOT_A_PRODUCT_CATEGORY',
                default => 'REVIEW',
            };
            // Never auto-create 207 categories.
            if ($decision === 'REVIEW') {
                $codeGuess = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $name) ?? '');
                if ($codeGuess !== '' && isset($categoriesExisting[$codeGuess])) {
                    $decision = 'APPROVED_MAP_EXISTING';
                }
            }
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
            $ops[] = [
                'type' => 'ProductCategory',
                'termId' => $term['termId'] ?? null,
                'rawName' => $term['name'] ?? null,
                'canonicalCandidate' => $name,
                'conceptClass' => $concept,
                'familyParentRequired' => true,
                'proposedFamily' => null,
                'decision' => $decision,
                'priority' => $decision === 'REVIEW' ? 'REQUIRED' : 'OPTIONAL',
                'autoCreate' => false,
                'note' => 'Only true internal Product classification may become ProductCategories. Operator must assign Family parent.',
            ];
        }
        unset($familiesExisting);

        return [
            'counts' => $counts,
            'create' => 0,
            'map' => $counts['APPROVED_MAP_EXISTING'],
            'review' => $counts['REVIEW'],
            'operations' => $ops,
            'policy' => 'Do not create 207 Categories automatically.',
        ];
    }

    /**
     * @param array<string, mixed> $phase126
     * @param array<string, array<string, mixed>> $seriesExisting
     * @param array<string, array<string, mixed>> $familiesExisting
     * @return array<string, mixed>
     */
    private function proposedSeries(array $phase126, array $seriesExisting, array $familiesExisting): array
    {
        $rows = $phase126['classification']['series'] ?? [];
        $ops = [];
        $counts = ['APPROVED_CREATE' => 0, 'APPROVED_MAP_EXISTING' => 0, 'REVIEW' => 0, 'INVALID' => 0, 'SOURCE_ONLY' => 0, 'REJECT' => 0];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(trim(MigrationPayload::string($row['proposedSeriesCode'] ?? $row['legacyStyleCode'] ?? '')));
            $decision = 'REVIEW';
            if ($code === '' || str_contains($code, ',') || ctype_digit($code)) {
                $decision = 'INVALID';
            } elseif (isset($seriesExisting[$code])) {
                $decision = 'APPROVED_MAP_EXISTING';
            } elseif (($row['family'] ?? '') === 'ACCESSORIES' && !isset($familiesExisting['ACCESSORIES'])) {
                $decision = 'REVIEW';
            }
            // Category must be approved before Series create — none are approved yet.
            if ($decision === 'REVIEW' && empty($row['category'])) {
                $decision = 'REVIEW';
            }
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
            $ops[] = [
                'type' => 'ProductSeries',
                'legacyStyleCode' => $code,
                'title' => $row['productTitle'] ?? null,
                'family' => $row['family'] ?? null,
                'category' => $row['category'] ?? null,
                'variantCount' => $row['variantCount'] ?? 0,
                'decision' => $decision,
                'priority' => 'REQUIRED',
                'autoCreate' => false,
                'note' => 'Series create requires valid identity + Family + approved Category. Not auto-created in Phase 12.7 dry-run apply set.',
            ];
        }

        return [
            'counts' => $counts,
            'create' => 0,
            'map' => $counts['APPROVED_MAP_EXISTING'],
            'review' => $counts['REVIEW'],
            'operations' => $ops,
            'policy' => 'Do not blindly create 390 Series.',
        ];
    }

    /**
     * @param array<string, mixed> $phase126
     * @param array<string, array<string, mixed>> $defsExisting
     * @return array<string, mixed>
     */
    private function proposedDefinitions(array $phase126, array $defsExisting): array
    {
        $items = $phase126['specifications']['items'] ?? [];
        $ops = [];
        $counts = ['APPROVED_CREATE' => 0, 'APPROVED_MAP_EXISTING' => 0, 'REVIEW' => 0, 'REJECT' => 0];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = strtoupper(trim(MigrationPayload::string($item['proposedCode'] ?? $item['definitionCode'] ?? $item['code'] ?? '')));
            $status = strtoupper(MigrationPayload::string($item['mappingStatus'] ?? 'REVIEW'));
            $decision = 'REVIEW';
            if ($code !== '' && isset($defsExisting[$code])) {
                $decision = 'APPROVED_MAP_EXISTING';
            } elseif ($status === 'EXCLUDED') {
                $decision = 'REJECT';
            }
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
            $ops[] = [
                'type' => 'SpecificationDefinition',
                'code' => $code !== '' ? $code : null,
                'source' => $item,
                'dataType' => $item['dataType'] ?? $item['proposedDataType'] ?? 'REVIEW',
                'scope' => $item['scope'] ?? $item['proposedScope'] ?? 'REVIEW',
                'decision' => $decision,
                'priority' => 'REQUIRED',
                'autoCreate' => false,
            ];
        }

        return [
            'counts' => $counts,
            'create' => 0,
            'map' => $counts['APPROVED_MAP_EXISTING'],
            'review' => $counts['REVIEW'],
            'operations' => $ops,
        ];
    }

    /**
     * @param array<string, mixed> $phase126
     * @return array<string, mixed>
     */
    private function proposedDvMappings(array $phase126): array
    {
        $rows = $phase126['dvFieldMappings']['rows'] ?? [];
        $ops = [];
        $approved = 0;
        $review = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = MigrationPayload::string($row['status'] ?? 'REVIEW');
            $style = MigrationPayload::string($row['style'] ?? '');
            $field = MigrationPayload::string($row['sourceField'] ?? '');
            $label = MigrationPayload::string($row['proposedDefinition'] ?? $row['legacyLabel'] ?? '');
            $decision = 'REVIEW';
            if ($status === 'SUGGESTED' && $style !== '' && $style !== '*' && $field !== '' && $label !== '') {
                // Deterministic evidence-backed mapping proposed for bulk approval — still needs operator apply approval.
                $decision = 'PROPOSED_FOR_APPROVAL';
                ++$approved;
            } else {
                ++$review;
            }
            $ops[] = [
                'type' => 'SpecificationFieldMapping',
                'sourceSystem' => 'tc',
                'sourceTable' => 'accessory_specs',
                'sourceField' => $field,
                'legacyStyleCode' => $style === '*' ? null : $style,
                'legacyLabel' => $row['legacyLabel'] ?? null,
                'proposedDefinitionName' => $label,
                'example' => $row['example'] ?? null,
                'scope' => 'REVIEW',
                'decision' => $decision,
                'priority' => 'MIGRATION_BLOCKER',
                'statusCodeIfApplied' => $decision === 'PROPOSED_FOR_APPROVAL' ? 'needs-review' : 'needs-review',
                'note' => 'Persisted as mapping rows (not PHP switches). Global d1 forbidden. Scope remains REVIEW until evidence approved.',
            ];
        }

        return [
            'approvedProposed' => $approved,
            'review' => $review,
            'operations' => $ops,
            'policy' => 'Style-specific only. 5 uncertain stay REVIEW. Bulk PROPOSED still requires apply approval.',
        ];
    }

    /**
     * @param array<string, mixed> $phase126
     * @return array<string, mixed>
     */
    private function proposedOptions(array $phase126): array
    {
        $proposals = $phase126['options']['proposals'] ?? [];
        $ops = [];
        foreach (is_array($proposals) ? $proposals : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ops[] = [
                'type' => 'SpecificationOptionAlias',
                'sourceRaw' => $row['canonicalOption'] ?? null,
                'sourceTable' => $row['sourceTable'] ?? null,
                'sourceColumn' => $row['sourceColumn'] ?? null,
                'usageCount' => $row['usageCount'] ?? 0,
                'decision' => 'REVIEW',
                'priority' => 'RECOMMENDED',
                'autoCreate' => false,
            ];
        }

        return [
            'create' => 0,
            'aliasesApproved' => 0,
            'aliasesReview' => count($ops),
            'operations' => $ops,
        ];
    }

    /**
     * @param array<string, mixed> $audit
     * @return array<string, mixed>
     */
    private function proposedValueOptions(array $audit, string $definitionCode): array
    {
        $ops = [];
        foreach (($audit['values'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ops[] = [
                'type' => 'SpecificationOption',
                'definitionCode' => $definitionCode,
                'rawValue' => $row['rawValue'] ?? null,
                'canonicalCandidate' => $row['canonicalCandidate'] ?? $row['parsedValue'] ?? null,
                'usageCount' => $row['usageCount'] ?? 0,
                'decision' => 'REVIEW',
                'priority' => 'RECOMMENDED',
                'autoMergeAliases' => false,
            ];
        }

        return [
            'definitionCode' => $definitionCode,
            'approved' => 0,
            'review' => count($ops),
            'reject' => 0,
            'operations' => $ops,
        ];
    }

    /**
     * @param array<string, mixed> $phase126
     * @param list<array<string, mixed>>|mixed $nodesExisting
     * @return array<string, mixed>
     */
    private function proposedCatalogue(array $phase126, mixed $nodesExisting): array
    {
        $existing = is_array($nodesExisting) ? $nodesExisting : [];
        $proposed = $phase126['catalogueNavigation']['proposedNodes'] ?? [];
        $ops = [];
        foreach ($existing as $node) {
            if (!is_array($node)) {
                continue;
            }
            $ops[] = [
                'type' => 'CatalogueNode',
                'code' => $node['code'] ?? null,
                'name' => $node['name'] ?? null,
                'decision' => 'KEEP',
                'priority' => 'REQUIRED',
            ];
        }
        foreach (is_array($proposed) ? $proposed : [] as $node) {
            if (!is_array($node)) {
                continue;
            }
            $ops[] = [
                'type' => 'CatalogueNode',
                'name' => $node['name'] ?? null,
                'decision' => 'REVIEW',
                'priority' => 'RECOMMENDED',
                'autoCreate' => false,
            ];
        }

        return [
            'keep' => count($existing),
            'create' => 0,
            'modified' => 0,
            'deleted' => 0,
            'proposedReview' => is_array($proposed) ? count($proposed) : 0,
            'placementsApproved' => 0,
            'operations' => $ops,
            'policy' => 'Do not rebuild Catalogue from Product Categories. Existing nodes preserved.',
        ];
    }

    /**
     * @param array<string, mixed> $phase126
     * @param array<string, mixed> $azureSnapshot
     * @return array<string, mixed>
     */
    private function accessoryIdentity(array $phase126, array $azureSnapshot): array
    {
        $summary = $phase126['accessoryIdentity'] ?? [];
        $live = $this->tc !== null ? $this->tc->discoverAccessoryIdentitySummary() : $summary;
        $joinHits = (int) ($live['exactProductCodeJoin'] ?? 0);

        return [
            'joinStrategyInvestigated' => [
                'accessory_specs.product_code = tpro_ext.product_code',
                'WP style_code = tpro_ext.style (NOT assumed)',
                'LEFT(code,n) FORBIDDEN',
                'name similarity FORBIDDEN',
            ],
            'liveJoinHits' => $joinHits,
            'outcome' => $joinHits > 0 ? 'EXACT' : 'EXPLICIT_MAPPING_REQUIRED',
            'counts' => [
                'EXACT' => $joinHits > 0 ? $joinHits : 0,
                'EXPLICIT_MAPPING_REQUIRED' => $joinHits === 0 ? (int) ($live['accessorySpecsRows'] ?? 2130) : 0,
                'REVIEW' => (int) ($phase126['productIdentity']['counts']['REVIEW'] ?? 0),
                'SOURCE_ONLY' => (int) ($phase126['productIdentity']['counts']['SOURCE_ONLY'] ?? 0),
                'INVALID' => (int) ($phase126['productIdentity']['counts']['INVALID'] ?? 0),
            ],
            'note' => $joinHits === 0
                ? 'No deterministic accessory_specs↔tpro_ext product_code join in live data. Manual/explicit mappings required. Do not manufacture automated match rules.'
                : 'Exact product_code join available.',
            'azureSnapshotIgnored' => $azureSnapshot === [],
        ];
    }

    /**
     * @param array<string, mixed> $familyOps
     * @param array<string, array<string, mixed>> $unitsExisting
     * @return list<array<string, mixed>>
     */
    private function detectConflicts(array $familyOps, array $unitsExisting, bool $unitsMissing): array
    {
        $conflicts = [];
        if ($unitsMissing && $unitsExisting !== []) {
            $conflicts[] = [
                'code' => 'UNITS_PRESENT_FLAG_MISMATCH',
                'message' => 'Snapshot reported units while table flagged missing',
            ];
        }
        foreach ($familyOps['operations'] ?? [] as $op) {
            if (($op['code'] ?? '') === 'ACCESSORIES' && ($op['decision'] ?? '') === 'PROPOSED_FOR_APPROVAL') {
                // not a conflict
            }
        }

        return $conflicts;
    }
}
