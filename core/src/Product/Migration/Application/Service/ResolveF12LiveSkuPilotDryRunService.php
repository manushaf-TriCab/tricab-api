<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Product\Migration\Application\DTO\F12LiveSkuPilotDryRunReport;
use TriCab\Core\Product\Migration\CatalogueNavigation\CatalogueNavigationDecision;
use TriCab\Core\Product\Migration\Support\MappingSampleValueInvariant;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Phase 12.8 corrected F12 live-SKU pilot dry-run.
 *
 * Never writes WordPress, tc, Azure, blobs, or pricing.
 * Mapping sampleValues are evidence only and never become payload values.
 */
final class ResolveF12LiveSkuPilotDryRunService
{
    public const STYLE = 'F12';
    public const PRODUCT_NAME = 'Inspection Tee';
    public const SOURCE_TABLE = 'accessory_specs';
    public const CABLE_OD_DEFINITION = 'NOM-CABLE-OD';
    public const APPLY_NOT_APPROVED = 'PILOT_APPLY_NOT_APPROVED';

    /**
     * @param array<string, mixed> $input
     */
    public function dryRun(array $input): F12LiveSkuPilotDryRunReport
    {
        $forbidden = MigrationPayload::int($input['forbiddenWriteAttempts'] ?? 0);
        $style = strtoupper(MigrationPayload::string($input['styleCode'] ?? self::STYLE, self::STYLE));
        $rows = $this->normalizeRows(MigrationPayload::assocList($input['liveSkuRows'] ?? []));
        $mappings = $this->approvedMappings(MigrationPayload::assocList($input['approvedMappings'] ?? []), $style);
        $inventory = $this->inventory($rows, MigrationPayload::int($input['tproExtStyleRowCount'] ?? 0));
        $identity = $this->identity($input, $style);
        $variants = $this->variants($inventory, $style);
        $specs = $this->specifications($inventory, $mappings, $style);
        $content = $this->content($input);
        $classification = $this->classification($input);
        $region = $this->region($input);
        $catalogue = $this->catalogue($input, $style);
        $collision = $this->collision($input, $style, $variants);
        $mutations = $this->mutations($identity, $variants, $specs, $region, $catalogue);
        $rollback = $this->rollback($identity, $variants, $specs, $region, $catalogue);
        $idempotency = $this->idempotency($mutations);
        $supersession = $this->supersession($input);
        $readiness = $this->readiness(
            $inventory,
            $identity,
            $variants,
            $specs,
            $classification,
            $region,
            $collision,
            $catalogue,
            $input,
        );

        $payload = [
            'phase' => '12.8-f12-corrected-live-sku-pilot-dry-run',
            'banner' => 'F12 — Inspection Tee. PREVIOUS PILOT PACKAGE: SUPERSEDED — SOURCE DRIFT. CORRECTED SOURCE: LIVE accessory_specs SKU ROWS.',
            'mode' => 'READ + RESOLVE + PROJECT',
            'applied' => false,
            'styleCode' => $style,
            'productName' => self::PRODUCT_NAME,
            'invariant' => [
                'rule' => MappingSampleValueInvariant::RULE,
                'sampleValuesMayBePayload' => false,
                'syntheticVariantF12Dash01WithoutSource' => false,
            ],
            'safety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => $forbidden,
                'targetWrites' => 0,
                'blobWrites' => 0,
                'pricingWrites' => 0,
                'catalogueTabWrites' => 0,
                'catalogueNodeWrites' => 0,
                'productNodeWrites' => 0,
            ],
            'sourceInventory' => $inventory,
            'identity' => $identity,
            'variants' => $variants,
            'authoritativeMappings' => $mappings['report'],
            'specifications' => $specs,
            'content' => $content,
            'classification' => $classification,
            'region' => $region,
            'catalogueNavigation' => $catalogue,
            'targetCollision' => $collision,
            'mutationPlan' => $mutations,
            'rollbackProjection' => $rollback,
            'idempotency' => $idempotency,
            'supersession' => $supersession,
            'readiness' => $readiness,
            'publication' => 'Draft',
        ];

        return new F12LiveSkuPilotDryRunReport(
            mode: 'READ + RESOLVE + PROJECT',
            applied: false,
            wordpressWrites: 0,
            tcWrites: 0,
            forbiddenWriteAttempts: $forbidden,
            targetWrites: 0,
            blobWrites: 0,
            pricingWrites: 0,
            payload: $payload,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function inventory(array $rows, int $tproExtStyleRows): array
    {
        $codes = [];
        $invalid = [];
        $missingDims = [];
        foreach ($rows as $row) {
            $code = MigrationPayload::string($row['productCode'] ?? '');
            $codes[] = $code;
            if ($code === '' || !str_starts_with(strtoupper($code), self::STYLE . '-')) {
                $invalid[] = $code === '' ? '(empty)' : $code;
            }
            $hasDim = false;
            foreach (['d1', 'd2', 'd3', 'd4', 'd5', 'd6'] as $field) {
                if (MigrationPayload::string($row[$field] ?? '') !== '') {
                    $hasDim = true;
                    break;
                }
            }
            if (!$hasDim) {
                $missingDims[] = $code;
            }
        }
        $counts = array_count_values(array_filter($codes, static fn (string $c): bool => $c !== ''));
        $duplicates = [];
        foreach ($counts as $code => $n) {
            if ($n > 1) {
                $duplicates[] = ['productCode' => $code, 'count' => $n];
            }
        }

        return [
            'discoveryQuery' => "accessory_specs.product_code LIKE 'F12-%'",
            'tproExtStyleRowsForF12' => $tproExtStyleRows,
            'tproExtStyleHasF12' => $tproExtStyleRows > 0,
            'rowsDiscovered' => count($rows),
            'uniqueProductCodes' => count($counts),
            'duplicateProductCodes' => $duplicates,
            'invalidProductCodes' => $invalid,
            'rowsMissingRequiredDimensions' => $missingDims,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function identity(array $input, string $style): array
    {
        $wp = MigrationPayload::assoc($input['wordpressIdentity'] ?? []);
        $target = MigrationPayload::assoc($input['target'] ?? []);
        $existing = MigrationPayload::assocList($target['existingProducts'] ?? []);
        $match = null;
        foreach ($existing as $product) {
            $code = strtoupper(MigrationPayload::string($product['styleCode'] ?? $product['productCode'] ?? ''));
            if ($code === $style) {
                $match = $product;
                break;
            }
        }
        $nameOnly = MigrationPayload::bool($input['allowNameOnlyMatch'] ?? false);
        $decision = $match === null ? 'CREATE_NEW_DRAFT' : 'MATCH_EXISTING_SAFE';
        $conflicts = [];
        if ($nameOnly) {
            $decision = 'BLOCKED';
            $conflicts[] = 'name_only_matching_forbidden';
        }

        return [
            'decision' => $decision,
            'styleCode' => $style,
            'productName' => self::PRODUCT_NAME,
            'wordpressPostId' => $wp['postId'] ?? $wp['wordpressPostId'] ?? 5877,
            'wordpressPostType' => $wp['postType'] ?? 'accessory',
            'wordpressTitle' => $wp['title'] ?? self::PRODUCT_NAME,
            'primaryJoinKey' => 'style_code',
            'nameOnlyMatching' => false,
            'targetProductMatch' => $match,
            'confidence' => $conflicts === [] ? 'HIGH' : 'NONE',
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    private function variants(array $inventory, string $style): array
    {
        $projected = [];
        $blocked = [];
        $duplicateCodes = [];
        foreach (MigrationPayload::assocList($inventory['duplicateProductCodes'] ?? []) as $dup) {
            $duplicateCodes[strtoupper(MigrationPayload::string($dup['productCode'] ?? ''))] = true;
        }
        foreach (MigrationPayload::assocList($inventory['rows'] ?? []) as $row) {
            $code = MigrationPayload::string($row['productCode'] ?? '');
            $upper = strtoupper($code);
            $base = [
                'legacyRowId' => $row['legacyRowId'] ?? null,
                'legacyProductCode' => $code,
                'proposedVariantCode' => $code,
                'product' => $style,
                'sourceEvidence' => self::SOURCE_TABLE . '.' . $code,
            ];
            if ($code === '' || !str_starts_with($upper, self::STYLE . '-')) {
                $blocked[] = $base + [
                    'identityDecision' => 'BLOCKED_INVALID_CODE',
                    'reason' => 'product_code is not a valid F12 SKU',
                ];
                continue;
            }
            if ($upper === self::STYLE . '-01' && MigrationPayload::bool($row['synthetic'] ?? false)) {
                $blocked[] = $base + [
                    'identityDecision' => 'BLOCKED_SYNTHETIC',
                    'reason' => 'Synthetic F12-01 is not allowed without live source evidence',
                ];
                continue;
            }
            if (isset($duplicateCodes[$upper])) {
                $blocked[] = $base + [
                    'identityDecision' => 'BLOCKED_DUPLICATE',
                    'reason' => 'Duplicate live SKU maps to the same target Variant; no automatic suffix',
                ];
                continue;
            }
            $projected[] = $base + [
                'identityDecision' => 'CREATE',
                'reason' => 'Live accessory_specs product_code is the Variant identity',
            ];
        }

        return [
            'projected' => $projected,
            'blocked' => $blocked,
            'ambiguous' => [],
            'variantsProjected' => count($projected),
            'variantsBlocked' => count($blocked),
            'syntheticF1201Used' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rawMaps
     * @return array{byField: array<string, array<string, mixed>>, report: list<array<string, mixed>>}
     */
    private function approvedMappings(array $rawMaps, string $style): array
    {
        $byField = [];
        $report = [];
        foreach ($rawMaps as $map) {
            $mapStyle = strtoupper(MigrationPayload::string($map['styleCode'] ?? $map['style_code'] ?? ''));
            if ($mapStyle !== $style) {
                continue;
            }
            $status = strtoupper(MigrationPayload::string($map['status'] ?? $map['c2Status'] ?? ''));
            if (!str_contains($status, 'APPROVED')) {
                continue;
            }
            $field = strtolower(MigrationPayload::string($map['sourceField'] ?? $map['legacyField'] ?? ''));
            $definition = MigrationPayload::string($map['targetDefinitionCode'] ?? $map['targetDefinition'] ?? '');
            if ($field === '' || $definition === '') {
                continue;
            }
            if (strtoupper($definition) === self::CABLE_OD_DEFINITION) {
                $report[] = [
                    'legacyField' => $field,
                    'targetDefinition' => $definition,
                    'targetUnit' => null,
                    'status' => 'REJECTED_CABLE_RULE',
                    'mappingSource' => 'batch-c2-approved',
                    'approvalStatus' => $status,
                    'reason' => 'Accessory OUTER-DIAMETER must not use cable od → NOM-CABLE-OD',
                    'sampleValuesEvidenceOnly' => MigrationPayload::stringList($map['sampleValues'] ?? []),
                ];
                continue;
            }
            $unit = $this->unitFromLabel(MigrationPayload::string($map['legacyLabel'] ?? $map['normalizedLabel'] ?? 'mm'));
            $entry = [
                'legacyField' => $field,
                'targetDefinition' => $definition,
                'targetUnit' => $unit,
                'status' => 'APPROVED_MAP',
                'mappingSource' => MigrationPayload::string($map['reason'] ?? $map['mappingProvenance'] ?? 'batch-c2-approved'),
                'approvalStatus' => $status,
                'sampleValuesEvidenceOnly' => MigrationPayload::stringList($map['sampleValues'] ?? []),
            ];
            $byField[$field] = $entry;
            $report[] = $entry;
        }

        return ['byField' => $byField, 'report' => $report];
    }

    /**
     * @param array<string, mixed> $inventory
     * @param array{byField: array<string, array<string, mixed>>, report: list<array<string, mixed>>} $mappings
     * @return array<string, mixed>
     */
    private function specifications(array $inventory, array $mappings, string $style): array
    {
        $byField = $mappings['byField'];
        $values = [];
        $parseFailures = [];
        $unmappedPopulated = [];
        $invariantViolations = [];
        $valueSets = [];
        foreach (MigrationPayload::assocList($inventory['rows'] ?? []) as $row) {
            $code = MigrationPayload::string($row['productCode'] ?? '');
            foreach (['d1', 'd2', 'd3', 'd4', 'd5', 'd6'] as $field) {
                $raw = trim(MigrationPayload::string($row[$field] ?? ''));
                if ($raw === '') {
                    continue;
                }
                if (!isset($byField[$field])) {
                    $unmappedPopulated[] = [
                        'legacyProductCode' => $code,
                        'legacyField' => $field,
                        'rawValue' => $raw,
                        'status' => 'REVIEW_UNMAPPED_POPULATED_FIELD',
                    ];
                    continue;
                }
                $map = $byField[$field];
                $normalized = $this->normalizeNumber($raw);
                if ($normalized === null) {
                    $parseFailures[] = [
                        'legacyProductCode' => $code,
                        'legacyField' => $field,
                        'rawValue' => $raw,
                        'status' => 'PARSE_FAILURE',
                    ];
                    continue;
                }
                $samples = MigrationPayload::stringList($map['sampleValuesEvidenceOnly'] ?? []);
                $projection = [
                    'legacyDatabase' => 'tc',
                    'legacyTable' => self::SOURCE_TABLE,
                    'legacyRowId' => MigrationPayload::string($row['legacyRowId'] ?? $code),
                    'legacyProductCode' => $code,
                    'legacyField' => $field,
                    'rawValue' => $raw,
                    'normalizedValue' => $normalized,
                    'targetDefinitionCode' => $map['targetDefinition'],
                    'targetUnitCode' => $map['targetUnit'],
                    'mappingProvenance' => $map['mappingSource'],
                    'mappingApproval' => $map['approvalStatus'],
                    'valueSource' => 'live_legacy_row',
                    'scope' => 'VARIANT',
                    'target' => 'VARIANT:' . $code,
                    'product' => $style,
                    'usedSampleValuesAsPayload' => false,
                ];
                if (MappingSampleValueInvariant::payloadViolatesInvariant($projection, $samples)) {
                    $invariantViolations[] = $projection;
                    continue;
                }
                $values[] = $projection;
                $def = MigrationPayload::string($map['targetDefinition'] ?? '');
                $valueSets[$def][] = $normalized;
            }
        }

        $scopeReport = [];
        $productValues = [];
        $variantValues = $values;
        foreach ($valueSets as $definition => $set) {
            $unique = array_values(array_unique($set));
            $identical = count($unique) <= 1;
            $scopeReport[] = [
                'definition' => $definition,
                'identicalAcrossSkus' => $identical,
                'distinctValues' => $unique,
                'scope' => $identical && count($set) > 1 ? 'CANDIDATE_PRODUCT_OR_VARIANT' : 'VARIANT',
            ];
        }

        return [
            'skuRows' => MigrationPayload::int($inventory['rowsDiscovered'] ?? 0),
            'populatedDimensionalValues' => count($values) + count($unmappedPopulated) + count($parseFailures),
            'mappedValues' => count($values),
            'parseFailures' => $parseFailures,
            'typeFailures' => [],
            'unitFailures' => [],
            'reviewFields' => $unmappedPopulated,
            'rejectFields' => [],
            'unmappedFields' => $unmappedPopulated,
            'requiredUnresolved' => count($parseFailures) + count($unmappedPopulated) + count($invariantViolations),
            'invariantViolations' => $invariantViolations,
            'scopeByDefinition' => $scopeReport,
            'projectedProductSpecificationValues' => $productValues,
            'projectedVariantSpecificationValues' => $variantValues,
            'cableNomCableOdApplied' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function content(array $input): array
    {
        $wp = MigrationPayload::assoc($input['wordpressContent'] ?? []);
        $title = MigrationPayload::string($wp['title'] ?? $wp['postTitle'] ?? self::PRODUCT_NAME, self::PRODUCT_NAME);
        $absent = static function (?string $value): array {
            $v = $value !== null && trim($value) !== '' ? $value : null;

            return ['source' => $v, 'target' => $v, 'status' => $v === null ? 'ABSENT' : 'PROJECTED'];
        };

        return [
            'name' => ['source' => $title, 'target' => $title],
            'shortDescription' => $absent(MigrationPayload::stringOrNull($wp['shortDescription'] ?? $wp['excerpt'] ?? null)),
            'longDescription' => $absent(MigrationPayload::stringOrNull($wp['longDescription'] ?? $wp['content'] ?? null)),
            'technicalDescription' => $absent(MigrationPayload::stringOrNull($wp['technicalDescription'] ?? null)),
            'features' => ['source' => MigrationPayload::stringList($wp['features'] ?? []), 'target' => MigrationPayload::stringList($wp['features'] ?? [])],
            'seoTitle' => $absent(MigrationPayload::stringOrNull($wp['seoTitle'] ?? null)),
            'seoDescription' => $absent(MigrationPayload::stringOrNull($wp['seoDescription'] ?? null)),
            'note' => 'Unsupported WordPress metadata is not migrated. Absent fields are not fabricated.',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function classification(array $input): array
    {
        $given = MigrationPayload::assoc($input['classification'] ?? []);
        $family = MigrationPayload::string(MigrationPayload::assoc($given['family'] ?? [])['target'] ?? $given['family'] ?? 'ACCESSORIES', 'ACCESSORIES');
        $category = MigrationPayload::string(MigrationPayload::assoc($given['category'] ?? [])['target'] ?? $given['category'] ?? 'CONDUITS-FITTINGS', 'CONDUITS-FITTINGS');
        $series = MigrationPayload::string(MigrationPayload::assoc($given['series'] ?? [])['target'] ?? $given['series'] ?? self::STYLE, self::STYLE);
        $status = ($family !== '' && $category !== '' && $series !== '') ? 'RESOLVED' : 'BLOCKED';

        return [
            'family' => ['source' => 'ACCESSORIES', 'target' => $family, 'mappingSource' => 'batch-a-approved', 'status' => $family !== '' ? 'RESOLVED' : 'BLOCKED'],
            'category' => ['source' => 'CONDUITS-FITTINGS', 'target' => $category, 'mappingSource' => 'batch-a-approved', 'status' => $category !== '' ? 'RESOLVED' : 'BLOCKED'],
            'series' => ['source' => self::STYLE, 'target' => $series, 'mappingSource' => 'batch-a-approved', 'status' => $series !== '' ? 'RESOLVED' : 'BLOCKED'],
            'autoCreateClassification' => false,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function region(array $input): array
    {
        $given = MigrationPayload::assoc($input['region'] ?? []);
        $regions = MigrationPayload::stringList($given['resolvedRegions'] ?? ['AU']);
        if ($regions === []) {
            $regions = ['AU'];
        }

        return [
            'legacyMarketEvidence' => MigrationPayload::string($given['legacyMarketEvidence'] ?? 'TriCab AU catalogue default for accessory styles'),
            'resolvedRegions' => $regions,
            'status' => 'RESOLVED_DRAFT_ASSIGNMENT',
            'blockers' => [],
            'globalPublishFallback' => false,
            'publication' => 'Draft',
            'separateCatalogueTreeByRegion' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function catalogue(array $input, string $style): array
    {
        $navInput = MigrationPayload::assoc($input['catalogueNavigation'] ?? []);
        $explicit = MigrationPayload::assocList($navInput['explicitMaps'] ?? $input['explicitCatalogueMaps'] ?? []);
        $service = new ResolveCatalogueNavigationAdditiveMappingService();
        $report = $service->dryRun([
            'forbiddenWriteAttempts' => 0,
            'legacyProducts' => MigrationPayload::assocList($navInput['legacyProducts'] ?? []),
            'explicitMaps' => $explicit,
            'target' => MigrationPayload::assoc($navInput['target'] ?? $input['target'] ?? []),
            'legacySource' => MigrationPayload::assoc($navInput['legacySource'] ?? []),
        ])->toArray();
        $payload = MigrationPayload::assoc($report['payload'] ?? []);
        $f12 = MigrationPayload::assoc($payload['f12'] ?? []);
        $f12['previousApplyBlockedBySourceDrift'] = MigrationPayload::bool($f12['applyBlockedBySourceDrift'] ?? false);
        $f12['applyBlockedBySourceDrift'] = false;
        $f12['note'] = 'Catalogue Navigation remains REVIEW: no proven target CatalogueNode for F12 WordPress placements. No Product→CatalogueNode link will be created. Previous F12 apply was PILOT_SOURCE_DRIFT and is superseded by this live-SKU package. This task does not apply F12.';
        $decision = MigrationPayload::string($f12['decision'] ?? CatalogueNavigationDecision::REVIEW_TARGET_NODE_MISSING);
        $inserts = MigrationPayload::int(MigrationPayload::assoc($payload['projectedMutations'] ?? [])['productNodeInserts'] ?? 0);
        $ready = in_array($decision, [CatalogueNavigationDecision::MAP_EXISTING_NODE, CatalogueNavigationDecision::EXISTING_IDENTICAL], true);
        $liveTarget = MigrationPayload::bool($navInput['targetLiveConnected'] ?? $input['targetLiveConnected'] ?? false);

        return [
            'banner' => 'ADDITIVE ONLY — EXISTING NAVIGATION PROTECTED',
            'targetLiveConnected' => $liveTarget,
            'targetLiveError' => $navInput['targetLiveError'] ?? $input['targetLiveError'] ?? null,
            'usedSnapshot' => !$liveTarget,
            'tabs' => MigrationPayload::assocList(MigrationPayload::assoc($payload['targetSnapshot'] ?? [])['tabs'] ?? []),
            'nodes' => MigrationPayload::assocList(MigrationPayload::assoc($payload['targetSnapshot'] ?? [])['nodes'] ?? []),
            'existingProtectedLinks' => MigrationPayload::assocList($payload['protectedExistingLinks'] ?? []),
            'legacyPlacements' => MigrationPayload::assocList($navInput['legacyPlacements'] ?? []),
            'f12' => $f12,
            'decision' => $decision,
            'projectedLinks' => $inserts,
            'catalogueNavigationReady' => $ready && $inserts >= 0,
            'draftPilotException' => 'NON_BLOCKING_FOR_DRAFT_PILOT',
            'noLinkWillBeCreatedThisTask' => true,
            'tabWrites' => 0,
            'nodeWrites' => 0,
            'linkWrites' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $variants
     * @return array<string, mixed>
     */
    private function collision(array $input, string $style, array $variants): array
    {
        $target = MigrationPayload::assoc($input['target'] ?? []);
        $live = MigrationPayload::bool($target['liveConnected'] ?? $input['targetLiveConnected'] ?? false);
        $products = MigrationPayload::assocList($target['existingProducts'] ?? []);
        $existingVariants = MigrationPayload::assocList($target['existingVariants'] ?? []);
        $productHit = false;
        $slugHit = false;
        foreach ($products as $product) {
            $code = strtoupper(MigrationPayload::string($product['styleCode'] ?? $product['productCode'] ?? ''));
            $slug = strtolower(MigrationPayload::string($product['slug'] ?? ''));
            if ($code === $style) {
                $productHit = true;
            }
            if ($slug === strtolower($style)) {
                $slugHit = true;
            }
        }
        $variantHits = [];
        $proposed = [];
        foreach (MigrationPayload::assocList($variants['projected'] ?? []) as $row) {
            $proposed[] = strtoupper(MigrationPayload::string($row['proposedVariantCode'] ?? ''));
        }
        foreach ($existingVariants as $existing) {
            $code = strtoupper(MigrationPayload::string($existing['variantCode'] ?? ''));
            if (in_array($code, $proposed, true)) {
                $variantHits[] = $code;
            }
        }
        $blocked = $productHit || $slugHit || $variantHits !== [];

        return [
            'liveTargetValidated' => $live,
            'status' => !$live ? 'TARGET_LIVE_REVALIDATION_REQUIRED' : ($blocked ? 'BLOCKED' : 'CLEAR'),
            'productCodeCollision' => $productHit,
            'styleIdentityCollision' => $productHit,
            'slugCollision' => $slugHit,
            'variantCodeCollisions' => $variantHits,
            'autoSuffix' => false,
        ];
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $variants
     * @param array<string, mixed> $specs
     * @param array<string, mixed> $region
     * @param array<string, mixed> $catalogue
     * @return array<string, mixed>
     */
    private function mutations(array $identity, array $variants, array $specs, array $region, array $catalogue): array
    {
        $productCreate = MigrationPayload::string($identity['decision'] ?? '') === 'CREATE_NEW_DRAFT' ? 1 : 0;

        return [
            'productCreate' => $productCreate,
            'productUpdate' => $productCreate === 1 ? 0 : 0,
            'variantsCreate' => MigrationPayload::int($variants['variantsProjected'] ?? 0),
            'variantsUpdate' => 0,
            'productSpecValuesInsert' => count(MigrationPayload::assocList($specs['projectedProductSpecificationValues'] ?? [])),
            'variantSpecValuesInsert' => count(MigrationPayload::assocList($specs['projectedVariantSpecificationValues'] ?? [])),
            'auRegionAssignments' => in_array('AU', MigrationPayload::stringList($region['resolvedRegions'] ?? []), true) ? 1 : 0,
            'catalogueRelationshipInserts' => 0,
            'catalogueRelationshipReviews' => MigrationPayload::string($catalogue['decision'] ?? '') === CatalogueNavigationDecision::MAP_EXISTING_NODE ? 0 : 1,
            'definitionCreates' => 0,
            'unitCreates' => 0,
            'optionCreates' => 0,
            'fieldMappingCreates' => 0,
            'classificationCreates' => 0,
            'pricingWrites' => 0,
            'mediaWrites' => 0,
            'documentWrites' => 0,
            'blobWrites' => 0,
            'publication' => 'Draft',
            'actualTargetWrites' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $variants
     * @param array<string, mixed> $specs
     * @param array<string, mixed> $region
     * @param array<string, mixed> $catalogue
     * @return array<string, mixed>
     */
    private function rollback(array $identity, array $variants, array $specs, array $region, array $catalogue): array
    {
        $variantCodes = [];
        foreach (MigrationPayload::assocList($variants['projected'] ?? []) as $row) {
            $variantCodes[] = MigrationPayload::string($row['proposedVariantCode'] ?? '');
        }

        return [
            'executed' => false,
            'migrationOwned' => [
                'product' => self::STYLE,
                'variants' => $variantCodes,
                'specValues' => count(MigrationPayload::assocList($specs['projectedVariantSpecificationValues'] ?? [])),
                'auAssignment' => in_array('AU', MigrationPayload::stringList($region['resolvedRegions'] ?? []), true),
                'catalogueLinks' => 0,
            ],
            'catalogueRollbackRows' => 0,
            'preExistingProtected' => 'NEVER_DELETE',
            'note' => 'Rollback projection only. Catalogue REVIEW yields zero catalogue rollback rows.',
        ];
    }

    /**
     * @param array<string, mixed> $mutations
     * @return array<string, mixed>
     */
    private function idempotency(array $mutations): array
    {
        return [
            'secondExecution' => [
                'productDuplicateCreates' => 0,
                'variantDuplicateCreates' => 0,
                'specDuplicateInserts' => 0,
                'auDuplicateAssignment' => 0,
                'catalogueDuplicateRelationship' => 0,
            ],
            'identicalMigrationOwnedRows' => 'NOOP',
            'differentRows' => 'CONFLICT',
            'manualOverwrite' => false,
            'projectionBasedOn' => $mutations,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function supersession(array $input): array
    {
        $prev = MigrationPayload::assoc($input['previousPackage'] ?? []);

        return [
            'previousPackage' => MigrationPayload::string($prev['path'] ?? 'core/docs/phase12.8/pilot-final-dry-run-package.json'),
            'previousStatus' => 'SUPERSEDED_BY_LIVE_SKU_RECONCILIATION',
            'reasonSuperseded' => 'PILOT_SOURCE_DRIFT',
            'previousApply' => [
                'status' => 'PILOT_SOURCE_DRIFT',
                'productWrites' => 0,
                'variantWrites' => 0,
                'specWrites' => 0,
                'catalogueWrites' => 0,
                'rolledBackSuccessfulMigration' => false,
                'stoppedBeforeCommit' => true,
            ],
            'replacementPackage' => 'core/docs/phase12.8/pilot-f12-live-sku-final-package.json',
            'historicalEvidencePreserved' => true,
        ];
    }

    /**
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $variants
     * @param array<string, mixed> $specs
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $region
     * @param array<string, mixed> $collision
     * @param array<string, mixed> $catalogue
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function readiness(
        array $inventory,
        array $identity,
        array $variants,
        array $specs,
        array $classification,
        array $region,
        array $collision,
        array $catalogue,
        array $input,
    ): array {
        $sourceReady = MigrationPayload::int($inventory['rowsDiscovered'] ?? 0) > 0
            && MigrationPayload::assocList($inventory['duplicateProductCodes'] ?? []) === []
            && MigrationPayload::stringList($inventory['invalidProductCodes'] ?? []) === [];
        $identityReady = MigrationPayload::string($identity['decision'] ?? '') === 'CREATE_NEW_DRAFT'
            || MigrationPayload::string($identity['decision'] ?? '') === 'MATCH_EXISTING_SAFE';
        $variantReady = MigrationPayload::int($variants['variantsProjected'] ?? 0) > 0
            && MigrationPayload::int($variants['variantsBlocked'] ?? 0) === 0;
        $classReady = MigrationPayload::string($classification['status'] ?? '') === 'RESOLVED';
        $specReady = MigrationPayload::int($specs['requiredUnresolved'] ?? 1) === 0
            && MigrationPayload::assocList($specs['invariantViolations'] ?? []) === []
            && MigrationPayload::bool($specs['cableNomCableOdApplied'] ?? true) === false;
        $regionReady = MigrationPayload::stringList($region['resolvedRegions'] ?? []) !== [];
        $liveTarget = MigrationPayload::string($collision['status'] ?? '') === 'CLEAR';
        $collisionReady = $liveTarget;
        $navReady = MigrationPayload::bool($catalogue['catalogueNavigationReady'] ?? false);
        $productDataReady = $sourceReady && $identityReady && $variantReady && $classReady && $specReady && $regionReady;
        $overall = 'NO';
        if (!$liveTarget) {
            $overall = 'NO';
        } elseif ($productDataReady) {
            $overall = $navReady ? 'YES' : 'YES_WITH_CATALOGUE_NAVIGATION_REVIEW';
        }

        return [
            'SOURCE_POPULATION_READY' => $sourceReady,
            'IDENTITY_READY' => $identityReady,
            'VARIANT_STRUCTURE_READY' => $variantReady,
            'CLASSIFICATION_READY' => $classReady,
            'SPECIFICATION_MAPPING_READY' => $specReady,
            'REGION_READY' => $regionReady,
            'TARGET_COLLISION_READY' => $collisionReady,
            'CATALOGUE_NAVIGATION_READY' => $navReady,
            'ROLLBACK_READY' => $productDataReady,
            'IDEMPOTENCY_READY' => $productDataReady,
            'PRODUCT_DATA_PILOT_READY' => $productDataReady,
            'OVERALL_DRAFT_PILOT_READY' => $overall,
            'TARGET_LIVE_REVALIDATION_REQUIRED' => MigrationPayload::string($collision['status'] ?? '') === 'TARGET_LIVE_REVALIDATION_REQUIRED',
            'publication' => 'Draft',
            'catalogueLinkWritesThisTask' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $code = trim(MigrationPayload::string($row['productCode'] ?? $row['product_code'] ?? ''));
            $out[] = [
                'legacyRowId' => MigrationPayload::string($row['legacyRowId'] ?? $row['id'] ?? $row['row_id'] ?? $code),
                'productCode' => $code,
                'styleRoot' => self::STYLE,
                'styleCode' => MigrationPayload::string($row['styleCode'] ?? $row['style_code'] ?? ''),
                'd1' => MigrationPayload::string($row['d1'] ?? ''),
                'd2' => MigrationPayload::string($row['d2'] ?? ''),
                'd3' => MigrationPayload::string($row['d3'] ?? ''),
                'd4' => MigrationPayload::string($row['d4'] ?? ''),
                'd5' => MigrationPayload::string($row['d5'] ?? ''),
                'd6' => MigrationPayload::string($row['d6'] ?? ''),
                'synthetic' => MigrationPayload::bool($row['synthetic'] ?? false),
            ];
        }
        usort(
            $out,
            static fn (array $a, array $b): int => MigrationPayload::string($a['productCode']) <=> MigrationPayload::string($b['productCode']),
        );

        return $out;
    }

    private function unitFromLabel(string $label): string
    {
        $lower = strtolower($label);

        return str_contains($lower, 'mm') || $lower === '' ? 'MM' : 'MM';
    }

    private function normalizeNumber(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        return number_format((float) $trimmed, 2, '.', '');
    }
}
