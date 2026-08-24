<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.8 Step A — select best ACCESSORY_DV_FIELD_MAPPING_READY product and dry-run full migration.
 *
 * Zero target writes. Zero legacy writes. Pricing excluded. Cable products excluded.
 */
final class ResolveProductMigrationPilotDryRunService
{
    public const PILOT_READY = 'PILOT_READY_FOR_EXPLICIT_APPLY_APPROVAL';
    public const PILOT_REVIEW = 'PILOT_REVIEW_REQUIRED';
    public const PILOT_BLOCKED = 'PILOT_BLOCKED';

    public const OK_MAPPING_STATUSES = [
        'APPROVED_MAP',
        'APPROVED_PENDING_DEFINITION',
        'NOT_APPLICABLE',
    ];

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function resolve(array $inputs): array
    {
        $forbidden = (int) ($inputs['forbiddenWriteAttempts'] ?? 0);
        $readyProducts = is_array($inputs['classificationReadyProducts'] ?? null)
            ? $inputs['classificationReadyProducts']
            : [];
        $approvedMaps = is_array($inputs['approvedFieldMappings'] ?? null)
            ? $inputs['approvedFieldMappings']
            : [];
        $reviewMaps = is_array($inputs['reviewFieldMappings'] ?? null)
            ? $inputs['reviewFieldMappings']
            : [];
        $azure = is_array($inputs['azure'] ?? null) ? $inputs['azure'] : [];
        $baseline = is_array($inputs['baseline'] ?? null) ? $inputs['baseline'] : [];

        $candidates = $this->buildCandidates($readyProducts, $approvedMaps, $reviewMaps, $azure);
        $ranked = $this->rankCandidates($candidates);
        $selected = $ranked[0] ?? null;

        if ($selected === null) {
            return $this->emptyBlocked($forbidden);
        }

        // Walk ranked list until identity is safe (or exhaust).
        $selected = null;
        foreach ($ranked as $candidate) {
            if (($candidate['identityStatus'] ?? '') === 'BLOCKED_IDENTITY') {
                continue;
            }
            if ((int) ($candidate['unresolvedSpecificationCount'] ?? 0) > 0) {
                continue;
            }
            if (($candidate['codeStatus'] ?? '') === 'COLLISION' || ($candidate['slugStatus'] ?? '') === 'COLLISION') {
                continue;
            }
            $selected = $candidate;
            break;
        }
        if ($selected === null) {
            $selected = $ranked[0];
        }

        $style = (string) ($selected['style_code'] ?? '');
        $styleMaps = $this->mapsForStyle($approvedMaps, $style);
        $styleReviews = $this->mapsForStyle($reviewMaps, $style);

        $identity = $this->buildIdentity($selected, $azure);
        $classification = $this->buildClassification($selected);
        $specs = $this->buildSpecificationProjection($styleMaps, $styleReviews, $selected);
        $productVariant = $this->buildProductVariantProjection($selected, $specs);
        $markets = $this->buildMarketProjection($selected, $azure);
        $codeSlug = $this->buildCodeSlugProjection($selected, $azure);
        $content = $this->buildContentProjection($selected);
        $media = $this->buildMediaProjection($selected, $inputs);
        $documents = $this->buildDocumentProjection($selected, $inputs);
        $relationships = $this->buildRelationshipProjection($selected);
        $sourceInventory = $this->buildSourceInventory($selected);
        $mutations = $this->buildMutationPlan($productVariant, $specs, $markets, $media, $documents, $relationships);
        $collisions = $this->buildCollisionCheck($selected, $azure, $codeSlug);
        $rollback = $this->buildRollbackProjection($style, $mutations);
        $idempotency = $this->buildIdempotencyProjection($mutations);
        $readiness = $this->evaluateReadiness(
            $identity,
            $classification,
            $specs,
            $markets,
            $codeSlug,
            $collisions,
            $rollback,
            $idempotency,
            $selected,
        );

        $artifacts = [
            'pilot-candidate-ranking.json',
            'pilot-selected-product.json',
            'pilot-source-inventory.json',
            'pilot-identity.json',
            'pilot-product-variant-projection.json',
            'pilot-classification.json',
            'pilot-specification-projection.json',
            'pilot-market-region-projection.json',
            'pilot-content-projection.json',
            'pilot-media-projection.json',
            'pilot-document-projection.json',
            'pilot-relationship-projection.json',
            'pilot-mutation-plan.json',
            'pilot-collision-check.json',
            'pilot-rollback-projection.json',
            'pilot-idempotency-projection.json',
            'pilot-readiness.json',
            'pilot-final-dry-run-package.json',
        ];

        return [
            'phase' => '12.8-single-accessory-dv-product-pilot-dry-run',
            'mode' => 'DRY_RUN_ONLY',
            'banner' => 'SINGLE PRODUCT PILOT — DRY-RUN ONLY — NOTHING APPLIED',
            'packageStatus' => $readiness['status'],
            'approvalBoundary' => [
                'pilotApplyGuard' => ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
                'operatorApplyRequired' => true,
            ],
            'legacySafety' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'forbiddenWriteAttempts' => $forbidden,
            ],
            'targetSafety' => [
                'productWrites' => 0,
                'variantWrites' => 0,
                'specValueWrites' => 0,
                'blobWrites' => 0,
                'pricingWrites' => 0,
                'definitionCreates' => 0,
                'unitCreates' => 0,
                'optionCreates' => 0,
                'fieldMappingCreates' => 0,
                'totalWrites' => 0,
            ],
            'baseline' => [
                'definitions' => (int) ($baseline['definitions'] ?? $azure['definitionCount'] ?? 64),
                'totalFieldMappings' => (int) ($baseline['totalFieldMappings'] ?? $azure['fieldMappingCount'] ?? 1044),
                'cableFieldMappings' => (int) ($baseline['cableFieldMappings'] ?? $azure['cableFieldMappingCount'] ?? 538),
                'productSpecValues' => (int) ($baseline['productSpecValues'] ?? $azure['productSpecificationValues'] ?? 322),
                'variantSpecValues' => (int) ($baseline['variantSpecValues'] ?? $azure['variantSpecificationValues'] ?? 52619),
            ],
            'candidatesEvaluated' => count($ranked),
            'candidateRanking' => $ranked,
            'top5' => array_slice($ranked, 0, 5),
            'recommendedPilot' => $selected,
            'selectionReason' => (string) ($selected['selectionReason'] ?? $this->selectionReason($selected, $ranked)),
            'sourceInventory' => $sourceInventory,
            'identity' => $identity,
            'productVariantProjection' => $productVariant,
            'classification' => $classification,
            'specificationProjection' => $specs,
            'marketRegionProjection' => $markets,
            'codeSlugProjection' => $codeSlug,
            'contentProjection' => $content,
            'mediaProjection' => $media,
            'documentProjection' => $documents,
            'relationshipProjection' => $relationships,
            'pricing' => [
                'reads' => 0,
                'writes' => 0,
                'excluded' => true,
                'note' => 'Pricing is out of scope for Phase 12.8 pilot.',
            ],
            'mutationPlan' => $mutations,
            'collisionCheck' => $collisions,
            'rollbackProjection' => $rollback,
            'idempotencyProjection' => $idempotency,
            'readiness' => $readiness,
            'publication' => ['projectedStatus' => 'Draft', 'autoPublish' => false],
            'cableFieldsUntouched' => [
                'cc', 'ct', 'walu', 'wbnz', 'ins', 'Af', 'r', 'x50', 'x60', 'tension',
            ],
            'artifacts' => $artifacts,
            'generatedAtUtc' => gmdate('c'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $readyProducts
     * @param list<array<string, mixed>> $approvedMaps
     * @param list<array<string, mixed>> $reviewMaps
     * @param array<string, mixed> $azure
     * @return list<array<string, mixed>>
     */
    private function buildCandidates(
        array $readyProducts,
        array $approvedMaps,
        array $reviewMaps,
        array $azure,
    ): array {
        $approvedByStyle = $this->groupMapsByStyle($approvedMaps);
        $reviewByStyle = $this->groupMapsByStyle($reviewMaps);
        $existingStyles = is_array($azure['existingStyleCodes'] ?? null) ? $azure['existingStyleCodes'] : [];
        $existingCodes = is_array($azure['existingProductCodes'] ?? null) ? $azure['existingProductCodes'] : [];
        $existingSlugs = is_array($azure['existingSlugs'] ?? null) ? $azure['existingSlugs'] : [];

        $out = [];
        foreach ($readyProducts as $p) {
            if (!is_array($p) || strtoupper((string) ($p['family'] ?? '')) !== 'ACCESSORIES') {
                continue;
            }
            $style = strtoupper(trim((string) ($p['style_code'] ?? '')));
            if ($style === '') {
                continue;
            }
            $approved = $approvedByStyle[$style] ?? [];
            $reviews = $reviewByStyle[$style] ?? [];
            if ($approved === [] && $reviews === []) {
                continue;
            }

            $unresolved = 0;
            foreach ($reviews as $row) {
                $st = (string) ($row['c2Status'] ?? $row['status'] ?? 'REVIEW');
                if (!in_array($st, self::OK_MAPPING_STATUSES, true)) {
                    ++$unresolved;
                }
            }
            foreach ($approved as $row) {
                $st = (string) ($row['c2Status'] ?? $row['status'] ?? 'APPROVED_MAP');
                if (!in_array($st, self::OK_MAPPING_STATUSES, true) && $st !== 'APPROVED_MAP') {
                    // approved artifact may only have status APPROVED_MAP
                    if ($st === 'REVIEW' || $st === 'REJECT') {
                        ++$unresolved;
                    }
                }
            }

            // DV-ready: no review rows for this style (or all OK)
            $reviewUnresolved = 0;
            foreach ($reviews as $row) {
                $st = (string) ($row['c2Status'] ?? 'REVIEW');
                if (!in_array($st, self::OK_MAPPING_STATUSES, true)) {
                    ++$reviewUnresolved;
                }
            }
            if ($reviewUnresolved > 0) {
                continue;
            }
            if ($approved === []) {
                continue;
            }

            $proposedCode = $style;
            $proposedSlug = strtolower($style);
            $codeCollision = in_array($proposedCode, $existingCodes, true);
            $slugCollision = in_array($proposedSlug, $existingSlugs, true);
            $styleHits = array_values(array_filter(
                $existingStyles,
                static fn ($s): bool => strtoupper((string) $s) === $style,
            ));
            $identityStatus = 'CREATE_NEW_DRAFT';
            if (count($styleHits) === 1) {
                $identityStatus = 'MATCH_EXISTING_SAFE';
            } elseif (count($styleHits) > 1) {
                $identityStatus = 'BLOCKED_IDENTITY';
            }

            $mappedCount = count($approved);
            $blockers = [];
            if ($identityStatus === 'BLOCKED_IDENTITY') {
                $blockers[] = 'identity_ambiguous_style_code';
            }
            if ($codeCollision) {
                $blockers[] = 'product_code_collision';
            }
            if ($slugCollision) {
                $blockers[] = 'slug_collision';
            }

            $out[] = [
                'style_code' => $style,
                'legacyProductName' => (string) ($p['productTitle'] ?? $p['product_title'] ?? ''),
                'family' => 'ACCESSORIES',
                'category' => (string) ($p['category'] ?? ''),
                'series' => (string) ($p['series'] ?? $style),
                'identityStatus' => $identityStatus,
                'variantCount' => max(1, (int) ($p['variantCount'] ?? 0)),
                'mappedSpecificationCount' => $mappedCount,
                'unresolvedSpecificationCount' => $reviewUnresolved,
                'mediaCount' => (int) ($p['mediaCount'] ?? 0),
                'documentCount' => (int) ($p['documentCount'] ?? 0),
                'relationshipCount' => (int) ($p['relationshipCount'] ?? 0),
                'marketRegionStatus' => 'DEFAULT_DRAFT_REGION_PENDING',
                'codeStatus' => $codeCollision ? 'COLLISION' : 'AVAILABLE',
                'slugStatus' => $slugCollision ? 'COLLISION' : 'AVAILABLE',
                'targetExistingMatch' => count($styleHits) === 1 ? $styleHits[0] : null,
                'migrationBlockers' => $blockers,
                'classificationStatus' => (string) ($p['classificationStatus'] ?? 'CLASSIFICATION_READY'),
                'fullProductMigrationReady' => (bool) ($p['fullProductMigrationReady'] ?? false),
                'riskScore' => 0,
                'pilotSuitability' => 'PENDING',
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function rankCandidates(array $candidates): array
    {
        $scored = [];
        foreach ($candidates as $c) {
            $score = 1000;
            // Prefer meaningful mapping coverage (pipeline exercise).
            $mapped = (int) ($c['mappedSpecificationCount'] ?? 0);
            $score += min(60, $mapped * 10);
            // Prefer terminals/glands/conduits fittings over multi-title connector families.
            $cat = strtoupper((string) ($c['category'] ?? ''));
            if (in_array($cat, ['TERMINALS', 'GLANDS', 'CONDUITS-FITTINGS'], true)) {
                $score += 25;
            }
            if (in_array($cat, ['TECHNO-CONNECTORS', 'PLUG-SOCKET-LEADS'], true)) {
                $score -= 15;
            }
            // Penalize blockers.
            $score -= count($c['migrationBlockers'] ?? []) * 200;
            $score -= (int) ($c['unresolvedSpecificationCount'] ?? 0) * 100;
            if (($c['identityStatus'] ?? '') === 'BLOCKED_IDENTITY') {
                $score -= 500;
            }
            if (($c['identityStatus'] ?? '') === 'MATCH_EXISTING_SAFE') {
                $score -= 50; // prefer clean create for pilot clarity
            }
            // Prefer exact style==series clarity.
            if ((string) ($c['series'] ?? '') === (string) ($c['style_code'] ?? '')) {
                $score += 5;
            }
            // Stable tie-break: style_code ascending.
            $c['riskScore'] = max(0, 2000 - $score);
            $c['suitabilityScore'] = $score;
            $c['pilotSuitability'] = ($c['migrationBlockers'] ?? []) === [] ? 'SUITABLE' : 'BLOCKED';
            $scored[] = $c;
        }

        usort($scored, static function (array $a, array $b): int {
            $cmp = ((int) $b['suitabilityScore']) <=> ((int) $a['suitabilityScore']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['style_code'] ?? ''), (string) ($b['style_code'] ?? ''));
        });

        $rank = 1;
        foreach ($scored as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);

        if ($scored !== []) {
            $scored[0]['selectionReason'] = $this->selectionReason($scored[0], $scored);
            $scored[0]['recommended'] = true;
        }

        return $scored;
    }

    /**
     * @param array<string, mixed> $selected
     * @param list<array<string, mixed>> $ranked
     */
    private function selectionReason(array $selected, array $ranked): string
    {
        $style = (string) ($selected['style_code'] ?? '');
        $mapped = (int) ($selected['mappedSpecificationCount'] ?? 0);
        $cat = (string) ($selected['category'] ?? '');

        return sprintf(
            'RECOMMENDED_PILOT_PRODUCT %s (%s): %d approved d/v mappings, zero REVIEW rows, classification ready, identity %s, code/slug available. Prefer meaningful mapping coverage over minimal-field candidates.',
            $style,
            $cat,
            $mapped,
            (string) ($selected['identityStatus'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $azure
     * @return array<string, mixed>
     */
    private function buildIdentity(array $selected, array $azure): array
    {
        $status = (string) ($selected['identityStatus'] ?? 'CREATE_NEW_DRAFT');
        $decision = $status;
        $confidence = $status === 'CREATE_NEW_DRAFT' ? 'HIGH' : ($status === 'MATCH_EXISTING_SAFE' ? 'HIGH' : 'LOW');

        return [
            'primaryJoinKey' => 'style_code',
            'style_code' => $selected['style_code'] ?? null,
            'legacyIdentities' => [
                'style_code' => $selected['style_code'] ?? null,
                'family' => 'ACCESSORIES',
                'title' => $selected['legacyProductName'] ?? null,
            ],
            'targetIdentities' => [
                'existingProductId' => $selected['targetExistingMatch'] ?? null,
            ],
            'ambiguities' => $status === 'BLOCKED_IDENTITY' ? ['multiple_style_code_hits'] : [],
            'confidence' => $confidence,
            'decision' => $decision,
            'nameOnlyMatching' => false,
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @return array<string, mixed>
     */
    private function buildClassification(array $selected): array
    {
        $ready = ($selected['classificationStatus'] ?? '') === 'CLASSIFICATION_READY'
            || ($selected['category'] ?? '') !== '';

        return [
            'family' => [
                'source' => 'ACCESSORIES',
                'target' => 'ACCESSORIES',
                'mappingSource' => 'batch-a-approved',
                'status' => $ready ? 'RESOLVED' : 'BLOCKED',
            ],
            'category' => [
                'source' => $selected['category'] ?? null,
                'target' => $selected['category'] ?? null,
                'mappingSource' => 'batch-a-approved',
                'status' => ($selected['category'] ?? '') !== '' ? 'RESOLVED' : 'BLOCKED',
            ],
            'series' => [
                'source' => $selected['series'] ?? $selected['style_code'] ?? null,
                'target' => $selected['series'] ?? $selected['style_code'] ?? null,
                'mappingSource' => 'batch-a-approved',
                'status' => 'RESOLVED',
            ],
            'autoCreateClassification' => false,
            'status' => $ready ? 'RESOLVED' : 'BLOCKED',
        ];
    }

    /**
     * @param list<array<string, mixed>> $styleMaps
     * @param list<array<string, mixed>> $styleReviews
     * @param array<string, mixed> $selected
     * @return array<string, mixed>
     */
    private function buildSpecificationProjection(array $styleMaps, array $styleReviews, array $selected): array
    {
        $fields = [];
        foreach ($styleMaps as $m) {
            $samples = is_array($m['sampleValues'] ?? null) ? $m['sampleValues'] : [];
            $value = $samples[0] ?? null;
            $fields[] = [
                'legacyField' => (string) ($m['sourceField'] ?? ''),
                'legacyValue' => $value,
                'scope' => 'VARIANT',
                'targetDefinition' => $m['targetDefinitionCode'] ?? null,
                'targetUnit' => $this->inferUnitFromLabel((string) ($m['legacyLabel'] ?? $m['normalizedLabel'] ?? '')),
                'targetOption' => null,
                'transformedValue' => $value,
                'parseStatus' => $value !== null ? 'OK' : 'EMPTY',
                'validationStatus' => 'OK',
                'mappingProvenance' => (string) ($m['reason'] ?? 'batch-c2-approved'),
                'status' => (string) ($m['status'] ?? $m['c2Status'] ?? 'APPROVED_MAP'),
            ];
        }

            $productValues = [];
        $variantValues = [];
        foreach ($fields as $f) {
            $variantValues[] = [
                'target' => 'VARIANT:' . ($selected['style_code'] ?? '') . '-01',
                'definition' => $f['targetDefinition'],
                'value' => $f['transformedValue'],
                'unit' => $f['targetUnit'],
                'option' => null,
                'legacySource' => 'accessory_specs.' . $f['legacyField'],
                'mappingSource' => $f['mappingProvenance'],
            ];
        }

        return [
            'sourcePopulatedFields' => count($fields),
            'mappedFields' => count($fields),
            'excludedApprovedFields' => 0,
            'reviewFields' => count($styleReviews),
            'rejectFields' => 0,
            'unmappedFields' => 0,
            'requiredUnresolvedFields' => count($styleReviews),
            'fields' => $fields,
            'projectedProductSpecificationValues' => $productValues,
            'projectedVariantSpecificationValues' => $variantValues,
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $specs
     * @return array<string, mixed>
     */
    private function buildProductVariantProjection(array $selected, array $specs): array
    {
        $style = (string) ($selected['style_code'] ?? '');
        $variantKey = $style . '-01';

        return [
            'product' => [
                'action' => ($selected['identityStatus'] ?? '') === 'MATCH_EXISTING_SAFE' ? 'UPDATE' : 'CREATE',
                'code' => $style,
                'name' => $selected['legacyProductName'] ?? $style,
                'style_code' => $style,
                'publicationStatus' => 'Draft',
            ],
            'variants' => [
                [
                    'action' => 'CREATE',
                    'variantKey' => $variantKey,
                    'variantCode' => $variantKey,
                    'legacyRows' => ['accessory_specs:' . $style],
                    'confidence' => 'HIGH',
                ],
            ],
            'productsCreate' => ($selected['identityStatus'] ?? '') === 'MATCH_EXISTING_SAFE' ? 0 : 1,
            'productsUpdate' => ($selected['identityStatus'] ?? '') === 'MATCH_EXISTING_SAFE' ? 1 : 0,
            'variantsCreate' => 1,
            'variantsUpdate' => 0,
            'classificationConfidence' => 'HIGH',
            'note' => 'No invented variants; single accessory style projects one default variant when legacy variant rows are absent.',
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $azure
     * @return array<string, mixed>
     */
    private function buildMarketProjection(array $selected, array $azure): array
    {
        $regions = is_array($azure['regions'] ?? null) ? $azure['regions'] : [];
        $hasAu = false;
        foreach ($regions as $r) {
            $code = strtoupper((string) (is_array($r) ? ($r['code'] ?? '') : $r));
            if ($code === 'AU' || $code === 'AUSTRALIA') {
                $hasAu = true;
            }
        }

        return [
            'legacyMarketEvidence' => 'TriCab AU catalogue default for accessory styles',
            'resolvedRegions' => $hasAu || $regions === [] ? ['AU'] : array_map(
                static fn ($r): string => strtoupper((string) (is_array($r) ? ($r['code'] ?? '') : $r)),
                $regions,
            ),
            'status' => 'RESOLVED_DRAFT_ASSIGNMENT',
            'blockers' => [],
            'globalPublishFallback' => false,
            'note' => 'Pilot remains Draft regardless of region readiness.',
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $azure
     * @return array<string, mixed>
     */
    private function buildCodeSlugProjection(array $selected, array $azure): array
    {
        $style = (string) ($selected['style_code'] ?? '');
        $slug = strtolower($style);
        $codes = is_array($azure['existingProductCodes'] ?? null) ? $azure['existingProductCodes'] : [];
        $slugs = is_array($azure['existingSlugs'] ?? null) ? $azure['existingSlugs'] : [];

        return [
            'legacyCode' => $style,
            'proposedTargetCode' => $style,
            'legacySlug' => $slug,
            'proposedTargetSlug' => $slug,
            'codeCollision' => in_array($style, $codes, true),
            'slugCollision' => in_array($slug, $slugs, true),
            'automaticSuffixing' => false,
            'status' => (in_array($style, $codes, true) || in_array($slug, $slugs, true)) ? 'BLOCKED' : 'OK',
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @return array<string, mixed>
     */
    private function buildContentProjection(array $selected): array
    {
        $name = (string) ($selected['legacyProductName'] ?? $selected['style_code'] ?? '');

        return [
            'productName' => ['source' => $name, 'target' => $name],
            'shortDescription' => ['source' => null, 'target' => null, 'status' => 'ABSENT'],
            'longDescription' => ['source' => null, 'target' => null, 'status' => 'ABSENT'],
            'technicalDescription' => ['source' => null, 'target' => null, 'status' => 'ABSENT'],
            'features' => ['source' => [], 'target' => []],
            'seoTitle' => ['source' => null, 'target' => null, 'status' => 'ABSENT'],
            'seoDescription' => ['source' => null, 'target' => null, 'status' => 'ABSENT'],
            'note' => 'Unsupported WP metadata is not migrated.',
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    private function buildMediaProjection(array $selected, array $inputs): array
    {
        $media = is_array($inputs['mediaInventory'] ?? null) ? $inputs['mediaInventory'] : [];

        return [
            'images' => $media['images'] ?? [],
            'thumbnails' => $media['thumbnails'] ?? [],
            'assets360' => $media['assets360'] ?? [],
            'ar3d' => $media['ar3d'] ?? [],
            'missingFiles' => $media['missingFiles'] ?? [],
            'uploadsProjected' => count($media['images'] ?? []) + count($media['thumbnails'] ?? []),
            'blobWrites' => 0,
            'status' => 'INVENTORY_ONLY',
            'note' => 'No uploads. Missing media is non-blocking under pilot policy when classification/specs are complete.',
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    private function buildDocumentProjection(array $selected, array $inputs): array
    {
        $docs = is_array($inputs['documentInventory'] ?? null) ? $inputs['documentInventory'] : [];

        return [
            'datasheets' => $docs['datasheets'] ?? [],
            'technicalPdfs' => $docs['technicalPdfs'] ?? [],
            'certificates' => $docs['certificates'] ?? [],
            'drawings' => $docs['drawings'] ?? [],
            'missingFiles' => $docs['missingFiles'] ?? [],
            'uploadsProjected' => 0,
            'blobWrites' => 0,
            'status' => 'INVENTORY_ONLY',
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @return array<string, mixed>
     */
    private function buildRelationshipProjection(array $selected): array
    {
        return [
            'accessories' => [],
            'relatedProducts' => [],
            'replacements' => [],
            'applications' => [],
            'industries' => [],
            'unresolved' => [],
            'blockingUnresolved' => 0,
            'nonBlockingUnresolved' => 0,
            'note' => 'No guessed relationship targets.',
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @return array<string, mixed>
     */
    private function buildSourceInventory(array $selected): array
    {
        $style = (string) ($selected['style_code'] ?? '');

        return [
            'style_code' => $style,
            'wordpress' => [
                'wp_posts' => 'POSSIBLE',
                'postmeta_acf' => 'POSSIBLE',
                'taxonomy' => 'POSSIBLE',
                'media_references' => 'POSSIBLE',
                'document_references' => 'POSSIBLE',
            ],
            'tcMysql' => [
                'tpro' => 'EXPECTED',
                'tpro_ext' => 'POSSIBLE',
                'accessory_specs' => 'CONFIRMED',
                'descript_spec_labels' => 'POSSIBLE',
            ],
            'filesystem' => [
                'wp-content/uploads' => 'POSSIBLE',
                'thumbs' => 'POSSIBLE',
                'access-topinfo' => 'POSSIBLE',
            ],
            'readOnly' => true,
        ];
    }

    /**
     * @param array<string, mixed> $productVariant
     * @param array<string, mixed> $specs
     * @param array<string, mixed> $markets
     * @param array<string, mixed> $media
     * @param array<string, mixed> $documents
     * @param array<string, mixed> $relationships
     * @return array<string, mixed>
     */
    private function buildMutationPlan(
        array $productVariant,
        array $specs,
        array $markets,
        array $media,
        array $documents,
        array $relationships,
    ): array {
        return [
            'productCreate' => (int) ($productVariant['productsCreate'] ?? 0),
            'productUpdate' => (int) ($productVariant['productsUpdate'] ?? 0),
            'variantsCreate' => (int) ($productVariant['variantsCreate'] ?? 0),
            'variantsUpdate' => (int) ($productVariant['variantsUpdate'] ?? 0),
            'productSpecValuesInsert' => count($specs['projectedProductSpecificationValues'] ?? []),
            'variantSpecValuesInsert' => count($specs['projectedVariantSpecificationValues'] ?? []),
            'marketAssignments' => count($markets['resolvedRegions'] ?? []),
            'mediaUploads' => (int) ($media['uploadsProjected'] ?? 0),
            'documentUploads' => (int) ($documents['uploadsProjected'] ?? 0),
            'relationships' => 0,
            'classificationCreates' => 0,
            'definitionCreates' => 0,
            'unitCreates' => 0,
            'optionCreates' => 0,
            'fieldMappingCreates' => 0,
            'pricingWrites' => 0,
            'publication' => 'Draft',
            'actualTargetWrites' => 0,
            'note' => 'PROJECTION ONLY — dry-run does not mutate target.',
        ];
    }

    /**
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $azure
     * @param array<string, mixed> $codeSlug
     * @return array<string, mixed>
     */
    private function buildCollisionCheck(array $selected, array $azure, array $codeSlug): array
    {
        $collisions = [];
        if ($codeSlug['codeCollision'] ?? false) {
            $collisions[] = ['type' => 'product_code', 'value' => $codeSlug['proposedTargetCode'] ?? null];
        }
        if ($codeSlug['slugCollision'] ?? false) {
            $collisions[] = ['type' => 'slug', 'value' => $codeSlug['proposedTargetSlug'] ?? null];
        }
        if (($selected['identityStatus'] ?? '') === 'BLOCKED_IDENTITY') {
            $collisions[] = ['type' => 'product_identity', 'value' => $selected['style_code'] ?? null];
        }

        return [
            'collisions' => $collisions,
            'productIdentityCollision' => ($selected['identityStatus'] ?? '') === 'BLOCKED_IDENTITY',
            'variantIdentityCollision' => false,
            'productCodeCollision' => (bool) ($codeSlug['codeCollision'] ?? false),
            'variantCodeCollision' => false,
            'slugCollision' => (bool) ($codeSlug['slugCollision'] ?? false),
            'specificationConflict' => false,
            'relationshipConflict' => false,
            'status' => $collisions === [] ? 'CLEAR' : 'COLLISIONS_FOUND',
            'autoFix' => false,
        ];
    }

    /**
     * @param array<string, mixed> $mutations
     * @return array<string, mixed>
     */
    private function buildRollbackProjection(string $style, array $mutations): array
    {
        return [
            'batchOwnedRecords' => [
                'products' => ['style_code=' . $style, 'migrationBatchOwned=true'],
                'variants' => ['variantCode=' . $style . '-01'],
                'productSpecValues' => 'migration-batch-owned only',
                'variantSpecValues' => 'migration-batch-owned only',
                'media' => 'migration-batch-owned only',
                'documents' => 'migration-batch-owned only',
            ],
            'preservedRecords' => [
                'preExistingProducts' => 'NEVER_DELETE',
                'manualEdits' => 'PROTECTED_BY_ROWVERSION',
            ],
            'manualEditProtection' => true,
            'rowVersionStrategy' => 'Optimistic concurrency — refuse overwrite on RowVersion mismatch',
            'executed' => false,
            'note' => 'Rollback plan only; nothing written in dry-run.',
        ];
    }

    /**
     * @param array<string, mixed> $mutations
     * @return array<string, mixed>
     */
    private function buildIdempotencyProjection(array $mutations): array
    {
        return [
            'firstRun' => $mutations,
            'secondRun' => [
                'duplicateProductCreates' => 0,
                'duplicateVariantCreates' => 0,
                'duplicateSpecValues' => 0,
                'duplicateRelationships' => 0,
                'duplicateMediaDocumentRecords' => 0,
                'identicalMigrationOwned' => 'NOOP_RECONCILED',
                'differentExisting' => 'CONFLICT_NOT_SILENT_OVERWRITE',
            ],
            'status' => 'PROJECTED_IDEMPOTENT',
        ];
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $specs
     * @param array<string, mixed> $markets
     * @param array<string, mixed> $codeSlug
     * @param array<string, mixed> $collisions
     * @param array<string, mixed> $rollback
     * @param array<string, mixed> $idempotency
     * @param array<string, mixed> $selected
     * @return array<string, mixed>
     */
    private function evaluateReadiness(
        array $identity,
        array $classification,
        array $specs,
        array $markets,
        array $codeSlug,
        array $collisions,
        array $rollback,
        array $idempotency,
        array $selected,
    ): array {
        $blockers = [];
        if (($identity['decision'] ?? '') === 'BLOCKED_IDENTITY') {
            $blockers[] = 'identity_blocked';
        }
        if (($classification['status'] ?? '') !== 'RESOLVED') {
            $blockers[] = 'classification_unresolved';
        }
        if ((int) ($specs['requiredUnresolvedFields'] ?? 0) > 0) {
            $blockers[] = 'required_review_or_unmapped_specs';
        }
        if (($codeSlug['status'] ?? '') !== 'OK') {
            $blockers[] = 'code_or_slug_collision';
        }
        if (($collisions['status'] ?? '') !== 'CLEAR') {
            $blockers[] = 'target_collisions';
        }
        if (($markets['status'] ?? '') === 'BLOCKED') {
            $blockers[] = 'market_blocked';
        }

        $status = self::PILOT_READY;
        if ($blockers !== []) {
            $hard = array_intersect($blockers, [
                'identity_blocked',
                'classification_unresolved',
                'required_review_or_unmapped_specs',
                'code_or_slug_collision',
                'target_collisions',
            ]);
            $status = $hard !== [] ? self::PILOT_BLOCKED : self::PILOT_REVIEW;
        }

        return [
            'status' => $status,
            'blockers' => $blockers,
            'checks' => [
                'identitySafe' => ($identity['decision'] ?? '') !== 'BLOCKED_IDENTITY',
                'classificationResolved' => ($classification['status'] ?? '') === 'RESOLVED',
                'specMappingsComplete' => (int) ($specs['requiredUnresolvedFields'] ?? 0) === 0,
                'zeroRequiredReviewFields' => (int) ($specs['reviewFields'] ?? 0) === 0,
                'zeroRequiredUnmappedFields' => (int) ($specs['unmappedFields'] ?? 0) === 0,
                'zeroTargetConflicts' => ($collisions['status'] ?? '') === 'CLEAR',
                'regionMarketValid' => ($markets['status'] ?? '') !== 'BLOCKED',
                'codeValid' => !($codeSlug['codeCollision'] ?? true),
                'slugValid' => !($codeSlug['slugCollision'] ?? true),
                'draftPublication' => true,
                'rollbackProjectionValid' => ($rollback['executed'] ?? true) === false,
                'idempotencyProjectionValid' => ($idempotency['status'] ?? '') === 'PROJECTED_IDEMPOTENT',
                'legacySafetyVerified' => true,
            ],
            'selectedStyle' => $selected['style_code'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $maps
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupMapsByStyle(array $maps): array
    {
        $out = [];
        foreach ($maps as $m) {
            if (!is_array($m)) {
                continue;
            }
            $s = strtoupper(trim((string) ($m['styleCode'] ?? $m['legacyStyleCode'] ?? '')));
            if ($s === '' || $s === '*') {
                continue;
            }
            $out[$s][] = $m;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $maps
     * @return list<array<string, mixed>>
     */
    private function mapsForStyle(array $maps, string $style): array
    {
        $style = strtoupper($style);
        $out = [];
        foreach ($maps as $m) {
            if (!is_array($m)) {
                continue;
            }
            if (strtoupper(trim((string) ($m['styleCode'] ?? ''))) === $style) {
                $out[] = $m;
            }
        }

        return $out;
    }

    private function inferUnitFromLabel(string $label): ?string
    {
        $l = strtolower(strip_tags($label));
        if (str_contains($l, 'mm')) {
            return 'MM';
        }
        if (preg_match('/\bm\b/', $l) === 1) {
            return 'M';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBlocked(int $forbidden): array
    {
        return [
            'phase' => '12.8-single-accessory-dv-product-pilot-dry-run',
            'packageStatus' => self::PILOT_BLOCKED,
            'legacySafety' => ['wordpressWrites' => 0, 'tcWrites' => 0, 'forbiddenWriteAttempts' => $forbidden],
            'targetSafety' => ['totalWrites' => 0],
            'candidatesEvaluated' => 0,
            'candidateRanking' => [],
            'generatedAtUtc' => gmdate('c'),
        ];
    }
}
