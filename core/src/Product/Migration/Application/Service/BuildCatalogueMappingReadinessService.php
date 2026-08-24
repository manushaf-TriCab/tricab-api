<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Application\DTO\CatalogueMappingReadinessReport;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Live\LegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Readiness\IdentityMatchMethod;
use TriCab\Core\Product\Migration\Readiness\MigrationReadinessStatus;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.6 — catalogue mapping & migration readiness.
 * Read-only against legacy sources. Does not migrate Products/Variants/media/prices.
 */
final class BuildCatalogueMappingReadinessService
{
    private const PRODUCT_POST_TYPES = ['cable', 'accessory'];

    public function __construct(
        private readonly LiveLegacySourceConfig $config,
        private readonly LegacyWordPressRepository $wordpress,
        private readonly LegacyTcRepository $tc,
        private readonly LegacySqlStatementLogger $sqlLogger,
        private readonly DiscoverLegacyMasterDataService $discovery,
        private readonly StyleCodeNormalizer $styleNormalizer = new StyleCodeNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $azureSnapshot
     */
    public function execute(array $azureSnapshot = []): CatalogueMappingReadinessReport
    {
        $started = microtime(true);
        $discoveryReport = $this->discovery->execute($azureSnapshot);
        $discovery = $discoveryReport->payload;

        $wpConnected = $this->config->wordpressConfigured();
        $tcConnected = $this->config->tcConfigured();

        $identity = $this->buildProductIdentity($wpConnected, $tcConnected, $discovery, $azureSnapshot);
        $families = $this->buildFamilyMappings($discovery, $azureSnapshot);
        $categories = $this->buildCategoryMappings($discovery);
        $series = $this->buildSeriesMappings($identity, $discovery, $azureSnapshot);
        $specUnits = $this->buildSpecificationUnitsStatus($azureSnapshot);
        $specs = $this->buildSpecificationMappings($discovery);
        $dv = $this->buildDvMappings($tcConnected);
        $scope = $tcConnected ? $this->tc->discoverAccessorySpecScopeEvidence() : [];
        $units = $this->buildUnitsMappings($discovery, $azureSnapshot, $specUnits);
        $options = $this->buildOptionProposals($discovery);
        $standards = $this->mergeValueAudits([
            $this->buildValueAudit($wpConnected, 'standards'),
            $this->buildValueAudit($wpConnected, 'standards_content'),
            $this->buildValueAudit($wpConnected, 'standards_content_al'),
        ], 'standards');
        $coreColours = $this->buildCoreColoursAudit($wpConnected);
        $certificates = $this->buildCertificateClassification($wpConnected);
        $approvals = $this->buildApprovalsAudit($certificates);
        $catalogue = $this->buildCatalogueNavigation($discovery, $azureSnapshot);
        $placements = $this->buildPlacementProposals($identity, $catalogue);
        $media = $this->buildMediaReadiness($discovery);
        $documents = $this->buildDocumentReadiness($certificates, $media);
        $existingAzure = $this->classifyExistingAzureProducts($azureSnapshot);
        $collisions = $this->buildCollisionDetection($identity, $existingAzure);
        $tpro = $tcConnected ? $this->tc->discoverTproExclusionSummary() : [];
        $variants = $tcConnected ? $this->tc->discoverVariantReadinessSummary() : [
            'statusCounts' => ['READY' => 0, 'REVIEW' => 0, 'BLOCKED' => 0, 'EXCLUDED' => 0],
        ];
        $accessoryIdentity = $tcConnected ? $this->tc->discoverAccessoryIdentitySummary() : [];
        $numericStyles = $tcConnected ? $this->tc->discoverNumericCableStyles() : [];

        $productCounts = $this->countByStatus($identity['products'] ?? []);
        $variantCounts = $variants['statusCounts'] ?? ['READY' => 0, 'REVIEW' => 0, 'BLOCKED' => 0, 'EXCLUDED' => 0];

        $scores = $this->dimensionScores(
            $productCounts,
            $families,
            $categories,
            $series,
            $specs,
            $dv,
            $units,
            $options,
            $catalogue,
            $media,
            $documents,
            $collisions,
            $variantCounts,
            $specUnits,
        );

        $blockers = $this->collectBlockers($scores, $specUnits, $media, $productCounts, $dv, $families);
        $masterPlan = $this->proposedMasterDataPlan($families, $categories, $series, $specs, $units, $options, $catalogue, $specUnits);

        $payload = [
            'executiveVerdict' => [
                'fullCatalogueDryRun' => 'NOT READY',
                'legacyWrites' => 'NO',
                'productMigration' => 'NO',
                'variantMigration' => 'NO',
                'mediaMigration' => 'NO',
                'pricingMigration' => 'NO',
                'nextPhase' => 'Phase 12.7 — Pre-Migration Master Data Fix & Seed (after approval)',
                'stopCondition' => 'STOP AND WAIT FOR APPROVAL — do not run Product migration dry-run yet.',
            ],
            'productIdentity' => $identity,
            'accessoryIdentity' => $accessoryIdentity,
            'numericCableStyles' => [
                'rows' => $numericStyles,
                'count' => count($numericStyles),
                'status' => count($numericStyles) > 0 ? MigrationReadinessStatus::REVIEW : MigrationReadinessStatus::SOURCE_ONLY,
                'note' => 'Do not convert numeric cable_style values into Product Series codes without evidence.',
            ],
            'classification' => [
                'families' => $families,
                'categories' => $categories,
                'series' => $series,
                'note' => 'Internal Family → Category → Series remains separate from Catalogue Navigation.',
            ],
            'specificationUnits' => $specUnits,
            'specifications' => $specs,
            'dvFieldMappings' => $dv,
            'productVsVariantScope' => $scope,
            'units' => $units,
            'options' => $options,
            'standards' => $standards,
            'approvals' => $approvals,
            'coreColours' => $coreColours,
            'certificates' => $certificates,
            'catalogueNavigation' => $catalogue,
            'productPlacements' => $placements,
            'media' => $media,
            'documents' => $documents,
            'existingAzureProducts' => $existingAzure,
            'collisions' => $collisions,
            'tproExclusions' => $tpro,
            'variants' => $variants,
            'productReadiness' => [
                'counts' => $productCounts,
                'eligibilityPolicy' => [
                    'READY_requires' => [
                        'identity ready',
                        'classification ready',
                        'Series ready',
                        'required Product specs mapped',
                        'no unresolved target collision',
                    ],
                    'mediaDocuments' => 'Tracked separately; Draft Product creation may proceed before assets if architecture permits — media currently BLOCKED.',
                    'partialMigration' => 'READY subset may migrate later while REVIEW/BLOCKED/INVALID remain quarantined. Not executed in Phase 12.6.',
                ],
            ],
            'variantReadiness' => [
                'counts' => $variantCounts,
                'eligibilityPolicy' => [
                    'READY_requires' => [
                        'parent Product ready',
                        'Variant identity valid',
                        'VariantCode valid/unique',
                        'required Variant specification mappings exist',
                        'required units/options resolved',
                        'no unresolved collision',
                    ],
                ],
            ],
            'dimensionScores' => $scores,
            'blockers' => $blockers,
            'proposedMasterDataChanges' => $masterPlan,
            'mappingApprovalPolicy' => [
                'permission' => 'PRODUCT.MIGRATION.MANAGE',
                'autoApproval' => false,
                'heuristicMatches' => 'SUGGESTED only — never treated as operator-approved',
                'persistentTables' => [
                    'migration.ClassificationMappings',
                    'migration.SpecificationFieldMappings',
                    'migration.SpecificationOptionAliases',
                    'migration.LegacyProductMappings',
                ],
            ],
            'discoveryReuse' => [
                'engine' => 'Phase 12 ProductMigrationService + DiscoverLegacyMasterDataService',
                'secondEngineCreated' => false,
                'stagingPreferred' => 'Extract once read-only → stage → review mappings against staging',
            ],
            'performance' => [
                'discoveryQueries' => $discoveryReport->queriesExecuted,
                'discoverySeconds' => $discoveryReport->executionSeconds,
            ],
        ];

        $log = array_map(static fn ($e) => $e->toArray(), $this->sqlLogger->entries());

        return new CatalogueMappingReadinessReport(
            generatedAtUtc: gmdate('Y-m-d\TH:i:s\Z'),
            wordpressConnected: $wpConnected,
            tcConnected: $tcConnected,
            payload: $payload,
            sqlLog: $log,
            queriesExecuted: count($log),
            executionSeconds: round(microtime(true) - $started, 3),
            readOnlyGuaranteed: true,
            fullCatalogueDryRun: 'NOT READY',
        );
    }

    /**
     * @param array<string, mixed> $discovery
     * @param array<string, mixed> $azureSnapshot
     * @return array<string, mixed>
     */
    private function buildProductIdentity(bool $wpConnected, bool $tcConnected, array $discovery, array $azureSnapshot): array
    {
        $tcStyleSet = [];
        foreach (($discovery['tc']['styleCodes'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(trim(MigrationPayload::string($row['style_code'] ?? '')));
            if ($code !== '') {
                $tcStyleSet[$code] = true;
            }
        }

        $existingByStyle = [];
        $existingMappings = 0;
        foreach (($azureSnapshot['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $legacy = MigrationPayload::string($product['legacyProductId'] ?? $product['internalCode'] ?? '');
            $publicId = MigrationPayload::string($product['publicId'] ?? '');
            if ($legacy !== '' && $publicId !== '') {
                $existingByStyle[strtoupper(trim($legacy))] = $publicId;
                if ((int) ($product['mappingCount'] ?? 0) > 0) {
                    ++$existingMappings;
                }
            }
        }

        $rows = $wpConnected
            ? $this->wordpress->discoverProductIdentityRows(self::PRODUCT_POST_TYPES)
            : [];

        $products = [];
        foreach ($rows as $row) {
            $raw = MigrationPayload::string($row['style_code_raw'] ?? '');
            $norm = $this->styleNormalizer->normalize($raw);
            $postType = MigrationPayload::string($row['post_type'] ?? '');
            $matchMethod = IdentityMatchMethod::NO_MATCH;
            $tcMatch = false;
            $targetIdentity = null;
            $status = MigrationReadinessStatus::SOURCE_ONLY;
            $issues = $norm['issues'];

            if ($norm['status'] === MigrationReadinessStatus::INVALID) {
                $matchMethod = IdentityMatchMethod::INVALID;
                $status = MigrationReadinessStatus::INVALID;
            } elseif ($norm['status'] === MigrationReadinessStatus::REVIEW) {
                $matchMethod = $norm['isCommaSeparated'] ? IdentityMatchMethod::INVALID : IdentityMatchMethod::NO_MATCH;
                $status = MigrationReadinessStatus::REVIEW;
            } elseif ($norm['normalized'] !== null) {
                $key = $norm['normalized'];
                if (isset($existingByStyle[$key])) {
                    $matchMethod = IdentityMatchMethod::EXPLICIT_MAPPING;
                    $tcMatch = isset($tcStyleSet[$key]);
                    $targetIdentity = $existingByStyle[$key];
                    $status = MigrationReadinessStatus::READY;
                } elseif (isset($tcStyleSet[$key])) {
                    $matchMethod = $postType === 'accessory'
                        ? IdentityMatchMethod::ACCESSORY_MAPPING
                        : IdentityMatchMethod::EXACT_STYLE;
                    $tcMatch = true;
                    $targetIdentity = 'NEW:' . $key;
                    // Exact style match alone is not enough without classification/series/specs.
                    $status = MigrationReadinessStatus::REVIEW;
                } else {
                    $matchMethod = IdentityMatchMethod::NO_MATCH;
                    $status = MigrationReadinessStatus::SOURCE_ONLY;
                    $issues[] = 'no_tc_style_match';
                }
            }

            if ($postType === 'certificate') {
                $status = MigrationReadinessStatus::EXCLUDED;
                $issues[] = 'certificate_cpt_not_product';
            }

            $products[] = [
                'wordpressPostId' => (int) ($row['post_id'] ?? 0),
                'postType' => $postType,
                'title' => MigrationPayload::string($row['post_title'] ?? ''),
                'styleCodeRaw' => $raw,
                'styleCodeNormalized' => $norm['normalized'],
                'tcSourceMatch' => $tcMatch,
                'matchMethod' => $matchMethod,
                'targetProductIdentity' => $targetIdentity,
                'status' => $status,
                'issues' => $issues,
            ];
        }

        return [
            'products' => $products,
            'counts' => $this->countByStatus($products),
            'existingLegacyMappingsObserved' => $existingMappings,
            'forbiddenHeuristics' => [
                'LEFT(tpro.ida, 3) prefix matching',
                'name-only matching',
            ],
            'allowedMatchMethods' => [
                IdentityMatchMethod::EXACT_STYLE,
                IdentityMatchMethod::EXPLICIT_MAPPING,
                IdentityMatchMethod::ACCESSORY_MAPPING,
                IdentityMatchMethod::NO_MATCH,
                IdentityMatchMethod::AMBIGUOUS,
                IdentityMatchMethod::INVALID,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $discovery
     * @param array<string, mixed> $azureSnapshot
     * @return list<array<string, mixed>>
     */
    private function buildFamilyMappings(array $discovery, array $azureSnapshot): array
    {
        $existing = [];
        foreach (($azureSnapshot['families'] ?? $discovery['azure']['families'] ?? []) as $row) {
            if (is_array($row)) {
                $existing[strtoupper(MigrationPayload::string($row['code'] ?? ''))] = $row;
            }
        }

        $cableCount = 0;
        $accessoryCount = 0;
        foreach (($discovery['wordpress']['postTypes'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(MigrationPayload::string($row['post_type'] ?? ''));
            $total = (int) ($row['total'] ?? 0);
            if ($type === 'cable') {
                $cableCount += $total;
            }
            if ($type === 'accessory') {
                $accessoryCount += $total;
            }
        }

        return [
            [
                'sourceRule' => 'wordpress post_type = cable',
                'affectedProductCount' => $cableCount,
                'existingTarget' => $existing['CABLE']['code'] ?? null,
                'proposedTarget' => 'CABLE',
                'proposedName' => 'Cables',
                'status' => isset($existing['CABLE']) ? MigrationReadinessStatus::READY : MigrationReadinessStatus::BLOCKED,
                'confidence' => 'high',
                'autoCreate' => false,
            ],
            [
                'sourceRule' => 'wordpress post_type = accessory',
                'affectedProductCount' => $accessoryCount,
                'existingTarget' => $existing['ACCESSORIES']['code'] ?? ($existing['ACCESSORY']['code'] ?? null),
                'proposedTarget' => 'ACCESSORIES',
                'proposedName' => 'Accessories',
                'status' => (isset($existing['ACCESSORIES']) || isset($existing['ACCESSORY']))
                    ? MigrationReadinessStatus::READY
                    : MigrationReadinessStatus::REVIEW,
                'confidence' => 'high',
                'autoCreate' => false,
                'note' => 'Do not create Accessories Family until operator approval (Phase 12.7).',
            ],
            [
                'sourceRule' => 'wordpress post_type = certificate',
                'affectedProductCount' => 290,
                'existingTarget' => null,
                'proposedTarget' => null,
                'status' => MigrationReadinessStatus::EXCLUDED,
                'confidence' => 'high',
                'note' => 'Certificates are documents, not Products.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $discovery
     * @return list<array<string, mixed>>
     */
    private function buildCategoryMappings(array $discovery): array
    {
        $out = [];
        foreach (($discovery['wordpress']['taxonomies'] ?? []) as $term) {
            if (!is_array($term)) {
                continue;
            }
            $name = MigrationPayload::string($term['name'] ?? '');
            $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $concept = $this->classifyTaxonomyConcept($decoded);
            $status = match ($concept) {
                'internal_category_candidate' => MigrationReadinessStatus::REVIEW,
                'marketing', 'voltage', 'application', 'industry', 'customer_navigation', 'certificate_region' => MigrationReadinessStatus::REVIEW,
                'obsolete' => MigrationReadinessStatus::EXCLUDED,
                default => MigrationReadinessStatus::SOURCE_ONLY,
            };
            $out[] = [
                'termId' => (int) ($term['term_id'] ?? 0),
                'taxonomy' => MigrationPayload::string($term['taxonomy'] ?? 'category'),
                'name' => $name,
                'nameDecoded' => $decoded,
                'slug' => MigrationPayload::string($term['slug'] ?? ''),
                'parent' => (int) ($term['parent'] ?? 0),
                'productUsageCount' => (int) ($term['usage_count'] ?? $term['product_count'] ?? 0),
                'proposedTargetCategory' => null,
                'mappingStatus' => $status,
                'conceptClass' => $concept,
                'confidence' => 'low',
                'reason' => 'WordPress taxonomy is not equivalent to Family/Category/Series; operator mapping required.',
                'canonicalizationProposal' => $decoded !== $name ? $decoded : null,
                'autoCreate' => false,
            ];
        }

        return $out;
    }

    private function classifyTaxonomyConcept(string $name): string
    {
        $n = strtolower($name);
        if ($n === '' || $n === 'uncategorized') {
            return 'obsolete';
        }
        if (in_array($n, ['au', 'nz', 'us', 'australia', 'new zealand', 'united states'], true)) {
            return 'certificate_region';
        }
        if (preg_match('/\d+\s*v|kV|voltage/i', $name) === 1) {
            return 'voltage';
        }
        if (preg_match('/marine|solar|mining|construction|building|industrial|telecom/i', $name) === 1) {
            return 'application';
        }
        if (preg_match('/news|kennards|store/i', $name) === 1) {
            return 'obsolete';
        }
        if (preg_match('/powersure|cable ties|lugs|heat shrink|plugs|sockets|ali-flex/i', $name) === 1) {
            return 'customer_navigation';
        }

        return 'internal_category_candidate';
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $discovery
     * @param array<string, mixed> $azureSnapshot
     * @return list<array<string, mixed>>
     */
    private function buildSeriesMappings(array $identity, array $discovery, array $azureSnapshot): array
    {
        $existingSeries = [];
        foreach (($azureSnapshot['series'] ?? $discovery['azure']['series'] ?? []) as $row) {
            if (is_array($row)) {
                $existingSeries[strtoupper(MigrationPayload::string($row['code'] ?? ''))] = $row;
            }
        }
        $variantByStyle = [];
        foreach (($discovery['tc']['variantCountsByStyle'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(trim(MigrationPayload::string($row['style_code'] ?? '')));
            if ($code !== '') {
                $variantByStyle[$code] = (int) ($row['variant_count'] ?? 0);
            }
        }

        $seen = [];
        $out = [];
        foreach (($identity['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $norm = $product['styleCodeNormalized'] ?? null;
            if (!is_string($norm) || $norm === '' || isset($seen[$norm])) {
                continue;
            }
            if (in_array($product['status'] ?? '', [MigrationReadinessStatus::INVALID, MigrationReadinessStatus::EXCLUDED], true)) {
                continue;
            }
            if (in_array('comma_or_multi_style_code', $product['issues'] ?? [], true)
                || in_array('numeric_legacy_style', $product['issues'] ?? [], true)
            ) {
                continue;
            }
            $seen[$norm] = true;
            $existing = $existingSeries[$norm] ?? null;
            $family = ($product['postType'] ?? '') === 'accessory' ? 'ACCESSORIES' : 'CABLE';
            $out[] = [
                'legacyStyleCode' => $norm,
                'productTitle' => $product['title'] ?? null,
                'family' => $family,
                'category' => null,
                'variantCount' => $variantByStyle[$norm] ?? 0,
                'existingSeriesMatch' => $existing['code'] ?? null,
                'proposedSeriesName' => $norm,
                'proposedSeriesCode' => $norm,
                'status' => $existing !== null ? MigrationReadinessStatus::READY : MigrationReadinessStatus::REVIEW,
                'autoCreate' => false,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $azureSnapshot
     * @return array<string, mixed>
     */
    private function buildSpecificationUnitsStatus(array $azureSnapshot): array
    {
        $units = $azureSnapshot['units'] ?? [];
        $present = is_array($units) && $units !== [];
        $missingObject = !$present;

        return [
            'expectedSchema' => '[product].[SpecificationUnits] (Phase 11 / create-product-schema.sql)',
            'actualSchema' => $present ? 'PRESENT' : 'MISSING_OR_EMPTY',
            'missingObjects' => $missingObject ? ['product.SpecificationUnits'] : [],
            'seedRequirements' => ['mm', 'mm2', 'kg', 'kg_per_km', 'A', 'V', 'C', 'm'],
            'dependencies' => [
                'SpecificationDefinitions.DefaultUnitId',
                'Product/Variant pricing UOM FKs (Phase 2C — separate concept, shared registry)',
            ],
            'status' => $missingObject ? MigrationReadinessStatus::BLOCKED : MigrationReadinessStatus::READY,
            'severity' => $missingObject ? 'MIGRATION_BLOCKER' : 'OK',
            'applyInThisPhase' => false,
            'additiveIdempotentPlan' => 'Run IF OBJECT_ID ... CREATE TABLE from create-product-schema.sql SpecificationUnits section; seed canonical units; do not drop existing product data.',
            'note' => 'Do not confuse Specification unit with Pricing UOM even if both use the same master registry.',
        ];
    }

    /**
     * @param array<string, mixed> $discovery
     * @return array<string, mixed>
     */
    private function buildSpecificationMappings(array $discovery): array
    {
        $defs = $discovery['specifications']['definitions'] ?? $discovery['proposedMappings']['specifications'] ?? [];
        $mapped = 0;
        $newProposed = 0;
        $review = 0;
        $blocked = 0;
        $items = [];
        foreach (is_array($defs) ? $defs : [] as $def) {
            if (!is_array($def)) {
                continue;
            }
            $status = strtoupper(MigrationPayload::string($def['status'] ?? $def['mappingStatus'] ?? 'REVIEW'));
            $normalized = match ($status) {
                'MATCHED', 'EXISTING_MATCH' => 'MATCHED',
                'NEW_REQUIRED', 'NEW_DEFINITION_REQUIRED' => 'NEW_DEFINITION_REQUIRED',
                'EXCLUDED' => 'EXCLUDED',
                default => 'REVIEW',
            };
            if ($normalized === 'MATCHED') {
                ++$mapped;
            } elseif ($normalized === 'NEW_DEFINITION_REQUIRED') {
                ++$newProposed;
            } elseif ($normalized === 'EXCLUDED') {
                // keep tally via review queue exclusion
            } else {
                ++$review;
            }
            $items[] = array_merge($def, ['mappingStatus' => $normalized, 'autoCreate' => false]);
        }
        if ($items === []) {
            $blocked = 1;
        }

        return [
            'items' => $items,
            'counts' => [
                'mapped' => $mapped,
                'newProposed' => $newProposed,
                'review' => $review,
                'blocked' => $blocked,
            ],
            'heuristicOnlyMatchesAreNotApproved' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDvMappings(bool $tcConnected): array
    {
        $rows = $tcConnected ? $this->tc->discoverDvFieldMappings() : [];
        $resolved = 0;
        $review = 0;
        $unmapped = 0;
        foreach ($rows as $row) {
            $status = MigrationPayload::string($row['status'] ?? 'REVIEW');
            if ($status === 'SUGGESTED') {
                ++$resolved;
            } elseif ($status === 'UNMAPPED') {
                ++$unmapped;
            } else {
                ++$review;
            }
        }

        return [
            'rows' => $rows,
            'totals' => [
                'styleFieldCombinations' => count($rows),
                'resolvedSuggested' => $resolved,
                'review' => $review,
                'unmapped' => $unmapped,
            ],
            'policy' => 'Mappings are keyed by legacy style + source field. GLOBAL d1=… forbidden unless live data proves global consistency.',
            'migrationBlocksUnknownMeanings' => true,
        ];
    }

    /**
     * @param array<string, mixed> $discovery
     * @param array<string, mixed> $azureSnapshot
     * @param array<string, mixed> $specUnits
     * @return array<string, mixed>
     */
    private function buildUnitsMappings(array $discovery, array $azureSnapshot, array $specUnits): array
    {
        $candidates = [
            ['legacy' => 'mm', 'code' => 'MM', 'symbol' => 'mm', 'quantity' => 'length'],
            ['legacy' => 'mm²', 'code' => 'MM2', 'symbol' => 'mm²', 'quantity' => 'area'],
            ['legacy' => 'mm2', 'code' => 'MM2', 'symbol' => 'mm²', 'quantity' => 'area'],
            ['legacy' => 'kg', 'code' => 'KG', 'symbol' => 'kg', 'quantity' => 'mass'],
            ['legacy' => 'kg/km', 'code' => 'KG_PER_KM', 'symbol' => 'kg/km', 'quantity' => 'linear_density'],
            ['legacy' => 'A', 'code' => 'A', 'symbol' => 'A', 'quantity' => 'current'],
            ['legacy' => 'V', 'code' => 'V', 'symbol' => 'V', 'quantity' => 'voltage'],
            ['legacy' => '°C', 'code' => 'C', 'symbol' => '°C', 'quantity' => 'temperature'],
            ['legacy' => 'm', 'code' => 'M', 'symbol' => 'm', 'quantity' => 'length'],
        ];
        $existing = [];
        foreach (($azureSnapshot['units'] ?? []) as $unit) {
            if (is_array($unit)) {
                $existing[strtoupper(MigrationPayload::string($unit['code'] ?? ''))] = $unit;
            }
        }
        $mapped = [];
        foreach ($candidates as $c) {
            $hit = $existing[$c['code']] ?? null;
            $mapped[] = [
                'legacyRepresentation' => $c['legacy'],
                'canonicalUnitCode' => $c['code'],
                'symbol' => $c['symbol'],
                'quantityType' => $c['quantity'],
                'existingSpecificationUnit' => $hit['code'] ?? null,
                'status' => ($specUnits['status'] ?? '') === MigrationReadinessStatus::BLOCKED
                    ? MigrationReadinessStatus::BLOCKED
                    : ($hit !== null ? MigrationReadinessStatus::READY : MigrationReadinessStatus::REVIEW),
            ];
        }
        unset($discovery);

        return [
            'mappings' => $mapped,
            'missingTable' => ($specUnits['status'] ?? '') === MigrationReadinessStatus::BLOCKED,
            'notPricingUom' => true,
        ];
    }

    /**
     * @param array<string, mixed> $discovery
     * @return array<string, mixed>
     */
    private function buildOptionProposals(array $discovery): array
    {
        $audits = $discovery['tc']['optionAudits'] ?? [];
        $proposals = [];
        foreach (is_array($audits) ? $audits : [] as $audit) {
            if (!is_array($audit)) {
                continue;
            }
            foreach (($audit['values'] ?? []) as $valueRow) {
                if (!is_array($valueRow)) {
                    continue;
                }
                $proposals[] = [
                    'sourceTable' => $audit['table'] ?? null,
                    'sourceColumn' => $audit['column'] ?? null,
                    'canonicalOption' => MigrationPayload::string($valueRow['value'] ?? ''),
                    'legacyAliases' => [],
                    'usageCount' => (int) ($valueRow['usage_count'] ?? 0),
                    'status' => MigrationReadinessStatus::REVIEW,
                    'autoCreate' => false,
                ];
            }
        }

        return [
            'proposals' => array_slice($proposals, 0, 500),
            'totalProposed' => count($proposals),
            'note' => 'No automatic option creation in Phase 12.6.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildValueAudit(bool $wpConnected, string $metaKey): array
    {
        $rows = $wpConnected
            ? $this->wordpress->discoverMetaValueCounts(self::PRODUCT_POST_TYPES, $metaKey, 500)
            : [];
        $exploded = [];
        foreach ($rows as $row) {
            $raw = MigrationPayload::string($row['value'] ?? '');
            $usage = (int) ($row['usage_count'] ?? 0);
            foreach ($this->explodeLegacyMultiValue($raw) as $parsed) {
                $key = strtoupper(trim($parsed));
                if ($key === '') {
                    continue;
                }
                if (!isset($exploded[$key])) {
                    $exploded[$key] = [
                        'rawValue' => $raw,
                        'parsedValue' => $parsed,
                        'canonicalCandidate' => $parsed,
                        'usageCount' => 0,
                        'aliasGroup' => null,
                        'mappingStatus' => MigrationReadinessStatus::REVIEW,
                        'autoMerge' => false,
                    ];
                }
                $exploded[$key]['usageCount'] += $usage;
            }
        }

        return [
            'metaKey' => $metaKey,
            'values' => array_values($exploded),
            'distinctParsed' => count($exploded),
            'rawRows' => count($rows),
            'note' => 'Do not silently canonicalize aliases (e.g. DNV GL / DNV-GL / DNVGL). Operator approval required.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $audits
     * @return array<string, mixed>
     */
    private function mergeValueAudits(array $audits, string $label): array
    {
        $merged = [];
        $rawRows = 0;
        foreach ($audits as $audit) {
            $rawRows += (int) ($audit['rawRows'] ?? 0);
            foreach (($audit['values'] ?? []) as $value) {
                if (!is_array($value)) {
                    continue;
                }
                $key = strtoupper(trim(MigrationPayload::string($value['parsedValue'] ?? '')));
                if ($key === '') {
                    continue;
                }
                if (!isset($merged[$key])) {
                    $merged[$key] = $value;
                } else {
                    $merged[$key]['usageCount'] = (int) ($merged[$key]['usageCount'] ?? 0) + (int) ($value['usageCount'] ?? 0);
                }
            }
        }

        return [
            'metaKey' => $label,
            'values' => array_values($merged),
            'distinctParsed' => count($merged),
            'rawRows' => $rawRows,
            'note' => 'Do not silently canonicalize aliases. Operator approval required.',
        ];
    }

    /**
     * @param array<string, mixed> $certificates
     * @return array<string, mixed>
     */
    private function buildApprovalsAudit(array $certificates): array
    {
        $exploded = [];
        foreach (($certificates['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = MigrationPayload::string($item['title'] ?? '');
            $cats = MigrationPayload::string($item['categories'] ?? '');
            foreach ($this->explodeLegacyMultiValue($title . ',' . $cats) as $parsed) {
                // Keep org-like tokens; skip pure region codes already handled as regions.
                if (preg_match('/^(AU|NZ|US)$/i', $parsed) === 1) {
                    continue;
                }
                $key = strtoupper(trim($parsed));
                if ($key === '' || strlen($key) < 2) {
                    continue;
                }
                if (!isset($exploded[$key])) {
                    $exploded[$key] = [
                        'rawValue' => $title,
                        'parsedValue' => $parsed,
                        'canonicalCandidate' => $parsed,
                        'usageCount' => 0,
                        'aliasGroup' => null,
                        'mappingStatus' => MigrationReadinessStatus::REVIEW,
                        'autoMerge' => false,
                    ];
                }
                ++$exploded[$key]['usageCount'];
            }
        }

        return [
            'metaKey' => 'certificate_cpt_titles_and_categories',
            'values' => array_values($exploded),
            'distinctParsed' => count($exploded),
            'rawRows' => (int) ($certificates['total'] ?? 0),
            'note' => 'No dedicated Product approvals meta key found. Candidates derived from certificate CPT. Do not auto-merge aliases.',
        ];
    }

    /**
     * @return list<string>
     */
    private function explodeLegacyMultiValue(string $raw): array
    {
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = strip_tags(str_replace(['<br>', '<br/>', '<br />', "\r\n", "\n"], ',', $decoded));
        // Best-effort unserialize for WP/ACF arrays without executing objects.
        if (str_starts_with($decoded, 'a:') || str_starts_with($decoded, 's:')) {
            $un = @unserialize($decoded, ['allowed_classes' => false]);
            if (is_array($un)) {
                $parts = [];
                array_walk_recursive($un, static function ($v) use (&$parts): void {
                    if (is_scalar($v) && (string) $v !== '') {
                        $parts[] = (string) $v;
                    }
                });

                return $parts;
            }
        }
        $parts = preg_split('/[,;|]+/', $decoded) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $t = trim($part);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out !== [] ? $out : ($decoded !== '' ? [$decoded] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCoreColoursAudit(bool $wpConnected): array
    {
        $rows = $wpConnected
            ? $this->wordpress->discoverMetaValueCounts(self::PRODUCT_POST_TYPES, 'core_colours', 300)
            : [];
        foreach (['core_colour', 'core_colours_matrix', 'colour_code', 'core_config'] as $alt) {
            if ($rows !== [] || !$wpConnected) {
                break;
            }
            $rows = $this->wordpress->discoverMetaValueCounts(self::PRODUCT_POST_TYPES, $alt, 300);
        }
        $classified = [];
        foreach ($rows as $row) {
            $raw = MigrationPayload::string($row['value'] ?? '');
            $compat = 'RAW_TEXT_REQUIRED';
            if ($raw === '') {
                $compat = 'INVALID';
            } elseif (strlen($raw) < 80 && !str_contains($raw, "\n") && preg_match('/^[A-Za-z0-9 ,\/+-]+$/', $raw) === 1) {
                $compat = 'VISUAL_EDITOR_COMPATIBLE';
            }
            $classified[] = [
                'rawValue' => $raw,
                'usageCount' => (int) ($row['usage_count'] ?? 0),
                'classification' => $compat,
                'note' => 'Do not force multi-core matrices into CODE_CORE_COLOUR single-select.',
            ];
        }

        return [
            'targetDefinition' => 'CORE-COLOURS (long text)',
            'values' => $classified,
            'counts' => [
                'VISUAL_EDITOR_COMPATIBLE' => count(array_filter($classified, static fn ($r) => $r['classification'] === 'VISUAL_EDITOR_COMPATIBLE')),
                'RAW_TEXT_REQUIRED' => count(array_filter($classified, static fn ($r) => $r['classification'] === 'RAW_TEXT_REQUIRED')),
                'INVALID' => count(array_filter($classified, static fn ($r) => $r['classification'] === 'INVALID')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCertificateClassification(bool $wpConnected): array
    {
        $rows = $wpConnected ? $this->wordpress->discoverCertificates(500) : [];
        $items = [];
        foreach ($rows as $row) {
            $cats = MigrationPayload::string($row['categories'] ?? '');
            $style = trim(MigrationPayload::string($row['style_code_raw'] ?? ''));
            $class = 'UNRESOLVED';
            if ($style !== '' && !str_contains($style, ',')) {
                $class = 'PRODUCT_DOCUMENT';
            } elseif (preg_match('/\b(AU|NZ|US)\b/i', $cats) === 1 && $style === '') {
                $class = 'SHARED_DOCUMENT';
            } elseif ($cats !== '' && $style === '') {
                $class = 'APPROVAL_EVIDENCE';
            }
            $items[] = [
                'postId' => (int) ($row['post_id'] ?? 0),
                'title' => MigrationPayload::string($row['post_title'] ?? ''),
                'categories' => $cats,
                'styleCodeRaw' => $style,
                'attachmentCount' => (int) ($row['attachment_count'] ?? 0),
                'classification' => $class,
                'status' => MigrationReadinessStatus::EXCLUDED,
                'note' => 'Certificate CPT is not a Product. Do not migrate in Phase 12.6.',
            ];
        }
        $byClass = [];
        foreach ($items as $item) {
            $c = $item['classification'];
            $byClass[$c] = ($byClass[$c] ?? 0) + 1;
        }

        return [
            'total' => count($items),
            'byClassification' => $byClass,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $discovery
     * @param array<string, mixed> $azureSnapshot
     * @return array<string, mixed>
     */
    private function buildCatalogueNavigation(array $discovery, array $azureSnapshot): array
    {
        $existingNodes = $azureSnapshot['catalogueNodes'] ?? $discovery['azure']['catalogueNodes'] ?? [];
        $frontend = $azureSnapshot['frontendCategories'] ?? $discovery['catalogueNavigation']['frontendCategories'] ?? [];
        $nodes = [];
        foreach (is_array($existingNodes) ? $existingNodes : [] as $node) {
            if (!is_array($node)) {
                continue;
            }
            $nodes[] = [
                'code' => $node['code'] ?? null,
                'name' => $node['name'] ?? null,
                'imageUrl' => $node['imageUrl'] ?? null,
                'classification' => 'KEEP',
                'note' => 'Do not delete/rebuild existing CatalogueNodes.',
            ];
        }
        $proposed = [];
        foreach (is_array($frontend) ? $frontend : [] as $name) {
            $label = is_string($name) ? $name : MigrationPayload::string($name);
            $hit = false;
            foreach ($nodes as $node) {
                if (strcasecmp((string) ($node['name'] ?? ''), $label) === 0) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $proposed[] = [
                    'name' => $label,
                    'classification' => 'ADD',
                    'status' => MigrationReadinessStatus::REVIEW,
                    'source' => 'frontend legacy panel config',
                ];
            }
        }

        return [
            'existingNodes' => $nodes,
            'proposedNodes' => $proposed,
            'legacyMenus' => $discovery['wordpress']['navMenus'] ?? [],
            'policy' => 'Do not transform WP taxonomy into Catalogue Navigation automatically.',
            'counts' => [
                'kept' => count($nodes),
                'proposedAdd' => count($proposed),
                'review' => count($proposed),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $catalogue
     * @return array<string, mixed>
     */
    private function buildPlacementProposals(array $identity, array $catalogue): array
    {
        $proposals = [];
        foreach (($identity['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            if (($product['postType'] ?? '') === 'accessory') {
                $proposals[] = [
                    'wordpressPostId' => $product['wordpressPostId'] ?? null,
                    'styleCode' => $product['styleCodeNormalized'] ?? $product['styleCodeRaw'] ?? null,
                    'proposedNodes' => ['Accessories'],
                    'status' => MigrationReadinessStatus::REVIEW,
                    'note' => 'Placement is separate from internal Category assignment.',
                ];
            }
        }

        return [
            'proposals' => array_slice($proposals, 0, 300),
            'totalProposed' => count($proposals),
            'existingNodeImagesPreserved' => true,
            'catalogueNodeCount' => count($catalogue['existingNodes'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $discovery
     * @return array<string, mixed>
     */
    private function buildMediaReadiness(array $discovery): array
    {
        $filesConfigured = $this->config->filesConfigured();
        $waived = $this->config->waiveFiles;
        $available = $filesConfigured && !$waived;
        $attachments = $discovery['wordpress']['attachments'] ?? [];

        return [
            'filesRoot' => $available ? 'AVAILABLE' : 'UNAVAILABLE',
            'waived' => $waived,
            'status' => $available ? MigrationReadinessStatus::REVIEW : MigrationReadinessStatus::BLOCKED,
            'ready' => 0,
            'missing' => null,
            'blocked' => $available ? 0 : 1,
            'attachmentSummary' => $attachments,
            'proposedRoles' => ['primary', 'gallery', 'cross-section', 'diagram'],
            'note' => 'Without filesystem access Media readiness = BLOCKED (not silently waived for full migration). No uploads in Phase 12.6.',
        ];
    }

    /**
     * @param array<string, mixed> $certificates
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function buildDocumentReadiness(array $certificates, array $media): array
    {
        $blocked = ($media['status'] ?? '') === MigrationReadinessStatus::BLOCKED;

        return [
            'status' => $blocked ? MigrationReadinessStatus::BLOCKED : MigrationReadinessStatus::REVIEW,
            'ready' => 0,
            'missing' => null,
            'blocked' => $blocked ? 1 : 0,
            'certificatePosts' => $certificates['total'] ?? 0,
            'targets' => ['ProductDocuments', 'shared/global document', 'certificate/approval evidence', 'exclude'],
            'note' => 'Do not copy files in Phase 12.6.',
        ];
    }

    /**
     * @param array<string, mixed> $azureSnapshot
     * @return array<string, mixed>
     */
    private function classifyExistingAzureProducts(array $azureSnapshot): array
    {
        $items = [];
        $counts = [
            'REAL_OR_MIGRATED' => 0,
            'DEVELOPMENT_SAMPLE' => 0,
            'DEBUG' => 0,
            'MANUAL_REAL' => 0,
            'UNKNOWN' => 0,
            'DELETED' => 0,
        ];
        foreach (($azureSnapshot['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $name = MigrationPayload::string($product['name'] ?? '');
            $code = MigrationPayload::string($product['internalCode'] ?? '');
            $mappingCount = (int) ($product['mappingCount'] ?? 0);
            $class = 'UNKNOWN';
            if (preg_match('/^Dbg|Debug/i', $name . ' ' . $code) === 1) {
                $class = 'DEBUG';
            } elseif (preg_match('/Phase85|sample|SAMPLE/i', $name . ' ' . $code) === 1) {
                $class = 'DEVELOPMENT_SAMPLE';
            } elseif ($mappingCount > 0) {
                $class = 'REAL_OR_MIGRATED';
            } elseif ($code !== '' || $name !== '') {
                $class = 'MANUAL_REAL';
            }
            $counts[$class]++;
            $items[] = [
                'productPublicId' => $product['publicId'] ?? null,
                'productCode' => $code,
                'name' => $name,
                'legacyProductId' => $product['legacyProductId'] ?? null,
                'mappingCount' => $mappingCount,
                'classification' => $class,
                'deletionPermission' => 'NONE — do not delete from classification',
            ];
        }

        return [
            'items' => $items,
            'counts' => $counts,
            'deleted' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $existingAzure
     * @return array<string, mixed>
     */
    private function buildCollisionDetection(array $identity, array $existingAzure): array
    {
        $byLegacy = [];
        foreach (($existingAzure['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $legacy = strtoupper(trim(MigrationPayload::string($item['legacyProductId'] ?? '')));
            if ($legacy !== '') {
                $byLegacy[$legacy] = $item;
            }
        }
        $outcomes = ['MATCH_EXISTING' => 0, 'CREATE_NEW' => 0, 'REVIEW_COLLISION' => 0];
        $rows = [];
        foreach (($identity['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $norm = strtoupper(trim(MigrationPayload::string($product['styleCodeNormalized'] ?? '')));
            $outcome = 'CREATE_NEW';
            if ($norm !== '' && isset($byLegacy[$norm])) {
                $outcome = 'MATCH_EXISTING';
            } elseif (($product['matchMethod'] ?? '') === IdentityMatchMethod::AMBIGUOUS) {
                $outcome = 'REVIEW_COLLISION';
            }
            $outcomes[$outcome]++;
            $rows[] = [
                'wordpressPostId' => $product['wordpressPostId'] ?? null,
                'styleCode' => $norm !== '' ? $norm : ($product['styleCodeRaw'] ?? null),
                'outcome' => $outcome,
            ];
        }

        return [
            'products' => $outcomes,
            'sample' => array_slice($rows, 0, 50),
            'matchRules' => [
                'explicit LegacyProductMapping',
                'source + source ID',
                'validated style code',
            ],
            'forbidden' => ['name-only matching'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function countByStatus(array $rows): array
    {
        $counts = [
            MigrationReadinessStatus::READY => 0,
            MigrationReadinessStatus::REVIEW => 0,
            MigrationReadinessStatus::BLOCKED => 0,
            MigrationReadinessStatus::SOURCE_ONLY => 0,
            MigrationReadinessStatus::INVALID => 0,
            MigrationReadinessStatus::EXCLUDED => 0,
        ];
        foreach ($rows as $row) {
            $status = MigrationPayload::string($row['status'] ?? '');
            if (isset($counts[$status])) {
                ++$counts[$status];
            }
        }

        return $counts;
    }

    /**
     * @param array<string, int> $productCounts
     * @param list<array<string, mixed>> $families
     * @param list<array<string, mixed>> $categories
     * @param list<array<string, mixed>> $series
     * @param array<string, mixed> $specs
     * @param array<string, mixed> $dv
     * @param array<string, mixed> $units
     * @param array<string, mixed> $options
     * @param array<string, mixed> $catalogue
     * @param array<string, mixed> $media
     * @param array<string, mixed> $documents
     * @param array<string, mixed> $collisions
     * @param array<string, int> $variantCounts
     * @param array<string, mixed> $specUnits
     * @return array<string, mixed>
     */
    private function dimensionScores(
        array $productCounts,
        array $families,
        array $categories,
        array $series,
        array $specs,
        array $dv,
        array $units,
        array $options,
        array $catalogue,
        array $media,
        array $documents,
        array $collisions,
        array $variantCounts,
        array $specUnits,
    ): array {
        $productTotal = max(1, array_sum($productCounts));
        $identityPct = round(100 * (($productCounts[MigrationReadinessStatus::READY] ?? 0) / $productTotal), 1);
        $familyReady = count(array_filter($families, static fn ($f) => ($f['status'] ?? '') === MigrationReadinessStatus::READY));
        $familyTotal = max(1, count(array_filter($families, static fn ($f) => ($f['status'] ?? '') !== MigrationReadinessStatus::EXCLUDED)));
        $seriesReady = count(array_filter($series, static fn ($s) => ($s['status'] ?? '') === MigrationReadinessStatus::READY));
        $seriesTotal = max(1, count($series));
        $specMapped = (int) ($specs['counts']['mapped'] ?? 0);
        $specAll = max(1, $specMapped + (int) ($specs['counts']['newProposed'] ?? 0) + (int) ($specs['counts']['review'] ?? 0));
        $dvTotal = max(1, (int) ($dv['totals']['styleFieldCombinations'] ?? 0));
        $dvResolved = (int) ($dv['totals']['resolvedSuggested'] ?? 0);
        $unitMapped = count(array_filter($units['mappings'] ?? [], static fn ($u) => ($u['status'] ?? '') === MigrationReadinessStatus::READY));
        $unitTotal = max(1, count($units['mappings'] ?? []));
        $variantTotal = max(1, array_sum($variantCounts));
        $mediaBlocked = ($media['status'] ?? '') === MigrationReadinessStatus::BLOCKED;

        return [
            'productIdentity' => ['percent' => $identityPct, 'status' => $identityPct >= 90 ? 'OK' : 'NOT_READY'],
            'classification' => [
                'percent' => round(100 * $familyReady / $familyTotal, 1),
                'categoriesProposed' => count($categories),
                'status' => 'NOT_READY',
            ],
            'series' => [
                'percent' => round(100 * $seriesReady / $seriesTotal, 1),
                'proposed' => count($series),
                'status' => 'NOT_READY',
            ],
            'productSpecifications' => [
                'percent' => round(100 * $specMapped / $specAll, 1),
                'status' => 'NOT_READY',
            ],
            'variantIdentity' => [
                'percent' => round(100 * (($variantCounts['READY'] ?? 0) / $variantTotal), 1),
                'status' => 'NOT_READY',
            ],
            'variantSpecifications' => [
                'percent' => round(100 * $dvResolved / $dvTotal, 1),
                'status' => 'NOT_READY',
            ],
            'units' => [
                'percent' => ($specUnits['status'] ?? '') === MigrationReadinessStatus::BLOCKED
                    ? 0.0
                    : round(100 * $unitMapped / $unitTotal, 1),
                'status' => ($specUnits['status'] ?? '') === MigrationReadinessStatus::BLOCKED
                    ? 'BLOCKED'
                    : ($unitMapped === $unitTotal ? 'OK' : 'NOT_READY'),
                'detail' => ($specUnits['status'] ?? '') === MigrationReadinessStatus::BLOCKED
                    ? 'SpecificationUnits missing'
                    : null,
            ],
            'options' => [
                'percent' => 0.0,
                'proposed' => (int) ($options['totalProposed'] ?? 0),
                'status' => 'NOT_READY',
            ],
            'cataloguePlacement' => [
                'percent' => 0.0,
                'existingKept' => (int) ($catalogue['counts']['kept'] ?? 0),
                'status' => 'NOT_READY',
            ],
            'media' => [
                'percent' => $mediaBlocked ? 0.0 : 0.0,
                'status' => $mediaBlocked ? 'BLOCKED' : 'NOT_READY',
                'detail' => $mediaBlocked ? 'files unavailable' : null,
            ],
            'documents' => [
                'percent' => 0.0,
                'status' => ($documents['status'] ?? '') === MigrationReadinessStatus::BLOCKED ? 'BLOCKED' : 'NOT_READY',
            ],
            'existingTargetCollision' => [
                'matchExisting' => (int) ($collisions['products']['MATCH_EXISTING'] ?? 0),
                'createNew' => (int) ($collisions['products']['CREATE_NEW'] ?? 0),
                'reviewCollision' => (int) ($collisions['products']['REVIEW_COLLISION'] ?? 0),
                'status' => 'REVIEW',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $scores
     * @param array<string, mixed> $specUnits
     * @param array<string, mixed> $media
     * @param array<string, int> $productCounts
     * @param array<string, mixed> $dv
     * @param list<array<string, mixed>> $families
     * @return list<array<string, mixed>>
     */
    private function collectBlockers(array $scores, array $specUnits, array $media, array $productCounts, array $dv, array $families = []): array
    {
        $out = [];
        if (($specUnits['status'] ?? '') === MigrationReadinessStatus::BLOCKED) {
            $out[] = [
                'code' => 'SPECIFICATION_UNITS_MISSING',
                'severity' => 'MIGRATION_BLOCKER',
                'message' => 'product.SpecificationUnits missing on validated Azure SQL database',
            ];
        }
        if (($media['status'] ?? '') === MigrationReadinessStatus::BLOCKED) {
            $out[] = [
                'code' => 'MEDIA_FILES_UNAVAILABLE',
                'severity' => 'MIGRATION_BLOCKER',
                'message' => 'LEGACY_FILES_ROOT unavailable/waived — media readiness BLOCKED',
            ];
        }
        if (($productCounts[MigrationReadinessStatus::READY] ?? 0) === 0) {
            $out[] = [
                'code' => 'NO_PRODUCTS_READY',
                'severity' => 'REQUIRED',
                'message' => 'No Product identities are READY for controlled dry-run yet',
            ];
        }
        if ((($dv['totals']['review'] ?? 0) + ($dv['totals']['unmapped'] ?? 0)) > 0) {
            $out[] = [
                'code' => 'DV_FIELD_MAPPINGS_INCOMPLETE',
                'severity' => 'MIGRATION_BLOCKER',
                'message' => 'd1–d7/v1–v4 style-specific mappings require operator review',
            ];
        }
        $accessoriesReady = false;
        foreach ($families as $family) {
            if (($family['proposedTarget'] ?? '') === 'ACCESSORIES'
                && ($family['status'] ?? '') === MigrationReadinessStatus::READY
            ) {
                $accessoriesReady = true;
                break;
            }
        }
        if (!$accessoriesReady) {
            $out[] = [
                'code' => 'ACCESSORIES_FAMILY_MISSING',
                'severity' => 'REQUIRED',
                'message' => 'Accessories Family proposed but not created (await Phase 12.7 approval)',
            ];
        }
        $out[] = [
            'code' => 'WP_TAXONOMY_NOT_CLASSIFICATION',
            'severity' => 'REQUIRED',
            'message' => 'WordPress category taxonomy is not Family/Category/Series',
        ];
        unset($scores);

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $families
     * @param list<array<string, mixed>> $categories
     * @param list<array<string, mixed>> $series
     * @param array<string, mixed> $specs
     * @param array<string, mixed> $units
     * @param array<string, mixed> $options
     * @param array<string, mixed> $catalogue
     * @param array<string, mixed> $specUnits
     * @return array<string, mixed>
     */
    private function proposedMasterDataPlan(
        array $families,
        array $categories,
        array $series,
        array $specs,
        array $units,
        array $options,
        array $catalogue,
        array $specUnits,
    ): array {
        return [
            'executeInPhase12_6' => false,
            'items' => [
                [
                    'type' => 'SpecificationUnits',
                    'action' => 'CREATE_TABLE_AND_SEED',
                    'priority' => 'MIGRATION_BLOCKER',
                    'detail' => $specUnits['additiveIdempotentPlan'] ?? null,
                ],
                [
                    'type' => 'ProductFamily',
                    'action' => 'CREATE',
                    'code' => 'ACCESSORIES',
                    'priority' => 'REQUIRED',
                    'autoCreate' => false,
                ],
                [
                    'type' => 'ProductCategory',
                    'action' => 'CREATE_SELECTED',
                    'countProposed' => count($categories),
                    'priority' => 'REQUIRED',
                    'autoCreate' => false,
                    'note' => 'Do not create one Category per WP term automatically.',
                ],
                [
                    'type' => 'ProductSeries',
                    'action' => 'CREATE_SELECTED',
                    'countProposed' => count($series),
                    'priority' => 'REQUIRED',
                    'autoCreate' => false,
                ],
                [
                    'type' => 'SpecificationDefinition',
                    'action' => 'CREATE_SELECTED',
                    'countProposed' => (int) ($specs['counts']['newProposed'] ?? 0),
                    'priority' => 'REQUIRED',
                    'autoCreate' => false,
                ],
                [
                    'type' => 'SpecificationUnit',
                    'action' => 'SEED',
                    'countProposed' => count($units['mappings'] ?? []),
                    'priority' => 'MIGRATION_BLOCKER',
                    'autoCreate' => false,
                ],
                [
                    'type' => 'SpecificationOption',
                    'action' => 'CREATE_SELECTED',
                    'countProposed' => (int) ($options['totalProposed'] ?? 0),
                    'priority' => 'RECOMMENDED',
                    'autoCreate' => false,
                ],
                [
                    'type' => 'CatalogueNode',
                    'action' => 'ADD_OR_UPDATE_REVIEW',
                    'countProposed' => count($catalogue['proposedNodes'] ?? []),
                    'priority' => 'RECOMMENDED',
                    'autoCreate' => false,
                ],
            ],
            'familiesProposed' => array_values(array_filter(
                $families,
                static fn ($f) => ($f['status'] ?? '') === MigrationReadinessStatus::REVIEW,
            )),
        ];
    }
}
