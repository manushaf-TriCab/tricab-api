<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

use TriCab\Core\Product\Domain\Service\LegacyPricingMaskGenerator;

/**
 * Phase 13.3 — deterministic zero-write base-cost migration dry run.
 * Pure in-memory; no repositories, no SQL, no apply path.
 */
final class BuildLegacyBaseCostDryRunService
{
    public function __construct(
        private readonly DbcoPriceListMappingContract $dbcoContract = new DbcoPriceListMappingContract(),
        private readonly LegacyPriceImportPolicy $policy = new LegacyPriceImportPolicy(),
        private readonly LegacyPriceIdentityPrecedence $identity = new LegacyPriceIdentityPrecedence(),
        private readonly LegacyPricingMaskGenerator $maskGenerator = new LegacyPricingMaskGenerator(),
    ) {
    }

    /**
     * @return array{
     *   resolutions: list<Phase133BaseCostDryRunResolution>,
     *   summary: array<string, mixed>,
     *   details: array<string, mixed>
     * }
     */
    public function execute(Phase133DryRunCatalogueSnapshot $snapshot): array
    {
        $scopedDbcos = $this->dbcoContract->phase133ScopedDbcos();
        $scopedSet = array_fill_keys($scopedDbcos, true);

        $sourceCounters = [
            'legacySourceRowsScanned' => count($snapshot->legacyPriceRows),
            'sourceRowsDbco2507' => 0,
            'sourceRowsDbco2517' => 0,
            'sourceRowsExcluded2519' => 0,
            'sourceRowsOtherDbco' => 0,
        ];

        /** @var list<array<string, mixed>> $scopedRows */
        $scopedRows = [];
        foreach ($snapshot->legacyPriceRows as $row) {
            $dbco = (int) ($row['dbco'] ?? 0);
            if ($dbco === 2507) {
                ++$sourceCounters['sourceRowsDbco2507'];
            } elseif ($dbco === 2517) {
                ++$sourceCounters['sourceRowsDbco2517'];
            } elseif ($dbco === 2519) {
                ++$sourceCounters['sourceRowsExcluded2519'];
                continue;
            } else {
                ++$sourceCounters['sourceRowsOtherDbco'];
                continue;
            }
            if (!isset($scopedSet[$dbco])) {
                continue;
            }
            $scopedRows[] = $row;
        }

        // Build exact index and variant catalogue
        $exactIndex = [];
        $variantsByCode = [];
        $variantMeta = [];
        foreach ($snapshot->variants as $v) {
            $vid = (string) $v['productVariantId'];
            $variantMeta[$vid] = $v;
            $codes = [];
            $vc = strtoupper(trim((string) ($v['variantCode'] ?? '')));
            $sku = strtoupper(trim((string) ($v['sku'] ?? '')));
            if ($vc !== '') {
                $codes[] = $vc;
            }
            if ($sku !== '' && $sku !== $vc) {
                $codes[] = $sku;
            }
            foreach ($codes as $code) {
                $exactIndex[$code][] = [
                    'productId' => (string) $v['productId'],
                    'productVariantId' => $vid,
                ];
                $variantsByCode[$code][] = [
                    'productId' => (string) $v['productId'],
                    'productVariantId' => $vid,
                    'variantCode' => $vc !== '' ? $vc : $sku,
                ];
            }
        }
        $maskIndex = $this->identity->buildMaskIndex($snapshot->variants);

        // Group by normalized code + dbco
        $groups = [];
        foreach ($scopedRows as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $dbco = (int) $row['dbco'];
            $key = $code . '|' . $dbco;
            $groups[$key][] = $row;
        }

        $latestStats = [
            'distinctCodeDbcoGroups' => count($groups),
            'latestLogicalGroups' => 0,
            'singleLatestGroups' => 0,
            'safeDuplicateGroups' => 0,
            'collapsedSourceRows' => 0,
            'differingCostGroups' => 0,
        ];

        /** @var list<Phase133BaseCostDryRunResolution> $resolutions */
        $resolutions = [];
        $maskSourceRecords = 0;
        $exactResolutions = 0;
        $explicitResolutions = 0;
        $maskDerivedVariants = 0;
        $unresolved = 0;
        $unresolvedNotMaterialized = 0;
        $ambiguous = 0;

        foreach ($groups as $groupKey => $rows) {
            [$normalizedCode, $dbcoStr] = explode('|', $groupKey, 2);
            $dbco = (int) $dbcoStr;

            $maxDc = null;
            foreach ($rows as $row) {
                $dc = isset($row['dc']) ? (string) $row['dc'] : '';
                if ($dc === '') {
                    continue;
                }
                if ($maxDc === null || strcmp($dc, $maxDc) > 0) {
                    $maxDc = $dc;
                }
            }

            $latestRows = [];
            foreach ($rows as $row) {
                $dc = isset($row['dc']) ? (string) $row['dc'] : '';
                if ($maxDc === null || $dc === $maxDc) {
                    $latestRows[] = $row;
                }
            }

            ++$latestStats['latestLogicalGroups'];
            $dupClass = $this->policy->classifyDuplicateGroup(array_map(
                static fn (array $r): array => ['cost' => (string) ($r['cost'] ?? '')],
                $latestRows,
            ));

            $sourceRowIds = array_values(array_map(
                static fn (array $r): string => (string) ($r['sourceRowId'] ?? ''),
                $latestRows,
            ));

            if ($dupClass === LegacyPriceImportPolicy::DUP_AMBIGUOUS) {
                ++$latestStats['differingCostGroups'];
                $resolutions[] = $this->blockedSource(
                    $sourceRowIds,
                    (string) ($latestRows[0]['code'] ?? $normalizedCode),
                    $normalizedCode,
                    $dbco,
                    $snapshot,
                    'DIFFERING_SAME_DATE_COST',
                    LegacyPriceImportPolicy::DUP_AMBIGUOUS,
                    [(string) ($latestRows[0]['cost'] ?? '')],
                );
                continue;
            }

            if (count($latestRows) === 1) {
                ++$latestStats['singleLatestGroups'];
            } else {
                ++$latestStats['safeDuplicateGroups'];
                $latestStats['collapsedSourceRows'] += count($latestRows) - 1;
            }

            $primary = $latestRows[0];
            $costRaw = $primary['cost'] ?? null;
            $legacyCode = (string) ($primary['code'] ?? $normalizedCode);
            $record = LegacyPriceRecord::fromLegacyRow([
                'sourceRowId' => implode(',', $sourceRowIds),
                'code' => $legacyCode,
                'dbco' => $dbco,
                'cost' => $costRaw,
                'dc' => $maxDc,
                'br' => $primary['br'] ?? null,
                'uwt' => $primary['uwt'] ?? null,
                'uwtcu' => $primary['uwtcu'] ?? null,
                'setup' => $primary['setup'] ?? null,
            ]);

            $mapping = $this->dbcoContract->forDbco($dbco);
            $priceListCode = $mapping['priceListCode'] ?? null;
            $currencyCode = $mapping['currencyCode'] ?? null;
            $plMeta = $priceListCode !== null ? ($snapshot->priceListsByCode[$priceListCode] ?? null) : null;
            $refMode = $snapshot->referenceResolutionMode;
            if ($plMeta !== null && ($plMeta['priceListId'] ?? null) !== null) {
                $refMode = Phase133BaseCostDryRunResolution::REF_DATABASE_ID;
            }

            $identity = $this->identity->resolveLegacyCode(
                $normalizedCode,
                $exactIndex,
                $snapshot->explicitLegacyCodeByVariantId,
                $variantsByCode,
                $maskIndex,
            );

            $identityType = $identity['identityType'];
            if ($identityType === PriceImportResolution::IDENTITY_EXACT) {
                ++$exactResolutions;
            } elseif ($identityType === PriceImportResolution::IDENTITY_EXPLICIT) {
                ++$explicitResolutions;
            } elseif ($identityType === PriceImportResolution::IDENTITY_MASK) {
                ++$maskSourceRecords;
                $maskDerivedVariants += count($identity['productVariantIds']);
            } elseif ($identityType === PriceImportResolution::IDENTITY_AMBIGUOUS) {
                ++$ambiguous;
            } else {
                ++$unresolved;
            }

            if ($identityType === PriceImportResolution::IDENTITY_UNRESOLVED) {
                // Materialize a sample only — full unresolved volume is reported in counters.
                if ($unresolved <= 200) {
                    $resolutions[] = $this->makeResolution(
                        sourceRowIds: $sourceRowIds,
                        record: $record,
                        identityType: $identityType,
                        productId: null,
                        productVariantId: null,
                        productVariantIds: [],
                        priceListCode: $priceListCode,
                        priceListId: $plMeta['priceListId'] ?? null,
                        currencyCode: $currencyCode,
                        currencyId: $plMeta['currencyId'] ?? null,
                        referenceResolution: $refMode,
                        duplicateDecision: $dupClass,
                        precedenceDecision: 'NONE',
                        collisionDecision: 'NONE',
                        existingTargetDecision: Phase133BaseCostDryRunResolution::EXISTING_NONE,
                        readiness: Phase133BaseCostDryRunResolution::SKIPPED,
                        reasons: array_merge($identity['reasons'], ['LEGACY_CODE_NOT_RELEVANT_TO_CURRENT_CATALOGUE']),
                        trace: ['group=' . $groupKey, 'latestDc=' . ($maxDc ?? '')],
                    );
                } else {
                    ++$unresolvedNotMaterialized;
                }
                continue;
            }

            if ($identityType === PriceImportResolution::IDENTITY_AMBIGUOUS) {
                $resolutions[] = $this->makeResolution(
                    sourceRowIds: $sourceRowIds,
                    record: $record,
                    identityType: $identityType,
                    productId: null,
                    productVariantId: null,
                    productVariantIds: $identity['productVariantIds'],
                    priceListCode: $priceListCode,
                    priceListId: $plMeta['priceListId'] ?? null,
                    currencyCode: $currencyCode,
                    currencyId: $plMeta['currencyId'] ?? null,
                    referenceResolution: $refMode,
                    duplicateDecision: $dupClass,
                    precedenceDecision: 'NONE',
                    collisionDecision: 'NONE',
                    existingTargetDecision: Phase133BaseCostDryRunResolution::EXISTING_NONE,
                    readiness: Phase133BaseCostDryRunResolution::BLOCKED,
                    reasons: array_merge($identity['reasons'], ['AMBIGUOUS_IDENTITY']),
                    trace: ['group=' . $groupKey],
                );
                continue;
            }

            $targetIds = $identity['productVariantIds'];
            if ($targetIds === [] && $identity['productVariantId'] !== null) {
                $targetIds = [$identity['productVariantId']];
            }

            foreach ($targetIds as $variantId) {
                $meta = $variantMeta[$variantId] ?? null;
                $resolutions[] = $this->makeResolution(
                    sourceRowIds: $sourceRowIds,
                    record: $record,
                    identityType: $identityType,
                    productId: $meta['productId'] ?? $identity['productId'],
                    productVariantId: $variantId,
                    productVariantIds: [$variantId],
                    priceListCode: $priceListCode,
                    priceListId: $plMeta['priceListId'] ?? null,
                    currencyCode: $currencyCode,
                    currencyId: $plMeta['currencyId'] ?? null,
                    referenceResolution: $refMode,
                    duplicateDecision: $dupClass,
                    precedenceDecision: 'NONE',
                    collisionDecision: 'NONE',
                    existingTargetDecision: Phase133BaseCostDryRunResolution::EXISTING_NONE,
                    readiness: Phase133BaseCostDryRunResolution::READY, // provisional; validated below
                    reasons: $identity['reasons'],
                    trace: ['group=' . $groupKey, 'identity=' . $identityType],
                );
            }
        }

        // Precedence: exact shadows mask for same variant+list
        $resolutions = $this->applyExactOverMaskPrecedence($resolutions);

        // Collisions by variant+priceList
        $resolutions = $this->applyTargetCollisions($resolutions);

        // Existing Azure comparison + cost readiness finalization
        $final = [];
        foreach ($resolutions as $r) {
            $final[] = $this->finalizeReadiness($r, $snapshot);
        }

        $summary = $this->summarize(
            $final,
            $sourceCounters,
            $latestStats,
            $exactResolutions,
            $explicitResolutions,
            $maskSourceRecords,
            $maskDerivedVariants,
            $unresolved,
            $unresolvedNotMaterialized,
            $ambiguous,
            $snapshot,
        );

        return [
            'resolutions' => $final,
            'summary' => $summary,
            'details' => [
                'zeroCostSamples' => $this->sampleByReadiness($final, Phase133BaseCostDryRunResolution::REVIEW, 50, static fn (Phase133BaseCostDryRunResolution $r): bool => str_contains(implode(' ', $r->reasons), 'ZERO') || $r->zeroCostDecision === LegacyPriceImportPolicy::ZERO_REVIEW),
                'collisionSamples' => $this->sampleByReadiness($final, Phase133BaseCostDryRunResolution::BLOCKED, 50, static fn (Phase133BaseCostDryRunResolution $r): bool => str_contains($r->collisionDecision, 'COLLISION') || in_array('TARGET_PRICE_COLLISION', $r->reasons, true) || in_array('DIFFERING_SAME_DATE_COST', $r->reasons, true)),
                'unresolvedSamples' => $this->sampleByReadiness($final, Phase133BaseCostDryRunResolution::SKIPPED, 40, static fn (Phase133BaseCostDryRunResolution $r): bool => $r->identityType === PriceImportResolution::IDENTITY_UNRESOLVED),
                'exactOverMaskSamples' => $this->sampleByReadiness($final, Phase133BaseCostDryRunResolution::SKIPPED, 40, static fn (Phase133BaseCostDryRunResolution $r): bool => $r->precedenceDecision === 'MASK_SHADOWED_BY_EXACT'),
            ],
        ];
    }

    /**
     * @param list<Phase133BaseCostDryRunResolution> $resolutions
     * @return list<Phase133BaseCostDryRunResolution>
     */
    private function applyExactOverMaskPrecedence(array $resolutions): array
    {
        $exactKeys = [];
        foreach ($resolutions as $r) {
            if ($r->identityType === PriceImportResolution::IDENTITY_EXACT
                && $r->productVariantId !== null
                && $r->priceListCode !== null
                && $r->readiness !== Phase133BaseCostDryRunResolution::BLOCKED
            ) {
                $exactKeys[$r->productVariantId . '|' . $r->priceListCode] = true;
            }
        }

        $out = [];
        foreach ($resolutions as $r) {
            if ($r->identityType === PriceImportResolution::IDENTITY_MASK
                && $r->productVariantId !== null
                && $r->priceListCode !== null
                && isset($exactKeys[$r->productVariantId . '|' . $r->priceListCode])
            ) {
                $out[] = new Phase133BaseCostDryRunResolution(
                    sourceRowIds: $r->sourceRowIds,
                    legacyCode: $r->legacyCode,
                    normalizedCode: $r->normalizedCode,
                    dbco: $r->dbco,
                    identityType: $r->identityType,
                    productId: $r->productId,
                    productVariantId: $r->productVariantId,
                    productVariantIds: $r->productVariantIds,
                    priceListCode: $r->priceListCode,
                    priceListId: $r->priceListId,
                    referenceResolution: $r->referenceResolution,
                    currencyCode: $r->currencyCode,
                    currencyId: $r->currencyId,
                    baseCost: $r->baseCost,
                    effectiveFrom: $r->effectiveFrom,
                    effectiveTo: $r->effectiveTo,
                    duplicateDecision: $r->duplicateDecision,
                    precedenceDecision: 'MASK_SHADOWED_BY_EXACT',
                    collisionDecision: $r->collisionDecision,
                    existingTargetDecision: $r->existingTargetDecision,
                    zeroCostDecision: $r->zeroCostDecision,
                    historyDecision: $r->historyDecision,
                    readiness: Phase133BaseCostDryRunResolution::SKIPPED,
                    reasons: array_values(array_unique(array_merge($r->reasons, ['MASK_SHADOWED_BY_EXACT']))),
                    trace: array_merge($r->trace, ['precedence=MASK_SHADOWED_BY_EXACT']),
                );
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * @param list<Phase133BaseCostDryRunResolution> $resolutions
     * @return list<Phase133BaseCostDryRunResolution>
     */
    private function applyTargetCollisions(array $resolutions): array
    {
        /** @var array<string, list<int>> $buckets */
        $buckets = [];
        foreach ($resolutions as $i => $r) {
            if ($r->productVariantId === null || $r->priceListCode === null) {
                continue;
            }
            if ($r->readiness === Phase133BaseCostDryRunResolution::SKIPPED
                || $r->readiness === Phase133BaseCostDryRunResolution::BLOCKED
            ) {
                continue;
            }
            $buckets[$r->productVariantId . '|' . $r->priceListCode][] = $i;
        }

        $flagged = [];
        foreach ($buckets as $indices) {
            if (count($indices) <= 1) {
                continue;
            }
            $costs = [];
            foreach ($indices as $i) {
                $costs[$resolutions[$i]->baseCost] = true;
            }
            $decision = count($costs) === 1 ? 'SAFE_EQUIVALENT_TARGET' : 'TARGET_PRICE_COLLISION';
            foreach ($indices as $i) {
                $flagged[$i] = $decision;
            }
        }

        $seenSafe = [];
        $out = [];
        foreach ($resolutions as $i => $r) {
            if (!isset($flagged[$i])) {
                $out[] = $r;
                continue;
            }
            $decision = $flagged[$i];
            if ($decision === 'SAFE_EQUIVALENT_TARGET') {
                // Keep first; skip subsequent as collapsed
                $key = ($r->productVariantId ?? '') . '|' . ($r->priceListCode ?? '');
                if (isset($seenSafe[$key])) {
                    $out[] = new Phase133BaseCostDryRunResolution(
                        sourceRowIds: $r->sourceRowIds,
                        legacyCode: $r->legacyCode,
                        normalizedCode: $r->normalizedCode,
                        dbco: $r->dbco,
                        identityType: $r->identityType,
                        productId: $r->productId,
                        productVariantId: $r->productVariantId,
                        productVariantIds: $r->productVariantIds,
                        priceListCode: $r->priceListCode,
                        priceListId: $r->priceListId,
                        referenceResolution: $r->referenceResolution,
                        currencyCode: $r->currencyCode,
                        currencyId: $r->currencyId,
                        baseCost: $r->baseCost,
                        effectiveFrom: $r->effectiveFrom,
                        effectiveTo: $r->effectiveTo,
                        duplicateDecision: $r->duplicateDecision,
                        precedenceDecision: $r->precedenceDecision,
                        collisionDecision: 'SAFE_EQUIVALENT_TARGET',
                        existingTargetDecision: $r->existingTargetDecision,
                        zeroCostDecision: $r->zeroCostDecision,
                        historyDecision: $r->historyDecision,
                        readiness: Phase133BaseCostDryRunResolution::SKIPPED,
                        reasons: array_values(array_unique(array_merge($r->reasons, ['SAFE_EQUIVALENT_TARGET_COLLAPSED']))),
                        trace: array_merge($r->trace, ['collision=SAFE_EQUIVALENT_TARGET']),
                    );
                    continue;
                }
                $seenSafe[$key] = true;
                $out[] = new Phase133BaseCostDryRunResolution(
                    sourceRowIds: $r->sourceRowIds,
                    legacyCode: $r->legacyCode,
                    normalizedCode: $r->normalizedCode,
                    dbco: $r->dbco,
                    identityType: $r->identityType,
                    productId: $r->productId,
                    productVariantId: $r->productVariantId,
                    productVariantIds: $r->productVariantIds,
                    priceListCode: $r->priceListCode,
                    priceListId: $r->priceListId,
                    referenceResolution: $r->referenceResolution,
                    currencyCode: $r->currencyCode,
                    currencyId: $r->currencyId,
                    baseCost: $r->baseCost,
                    effectiveFrom: $r->effectiveFrom,
                    effectiveTo: $r->effectiveTo,
                    duplicateDecision: $r->duplicateDecision,
                    precedenceDecision: $r->precedenceDecision,
                    collisionDecision: 'SAFE_EQUIVALENT_TARGET',
                    existingTargetDecision: $r->existingTargetDecision,
                    zeroCostDecision: $r->zeroCostDecision,
                    historyDecision: $r->historyDecision,
                    readiness: $r->readiness,
                    reasons: $r->reasons,
                    trace: array_merge($r->trace, ['collision=SAFE_EQUIVALENT_TARGET_KEPT']),
                );
                continue;
            }

            $out[] = new Phase133BaseCostDryRunResolution(
                sourceRowIds: $r->sourceRowIds,
                legacyCode: $r->legacyCode,
                normalizedCode: $r->normalizedCode,
                dbco: $r->dbco,
                identityType: $r->identityType,
                productId: $r->productId,
                productVariantId: $r->productVariantId,
                productVariantIds: $r->productVariantIds,
                priceListCode: $r->priceListCode,
                priceListId: $r->priceListId,
                referenceResolution: $r->referenceResolution,
                currencyCode: $r->currencyCode,
                currencyId: $r->currencyId,
                baseCost: $r->baseCost,
                effectiveFrom: $r->effectiveFrom,
                effectiveTo: $r->effectiveTo,
                duplicateDecision: $r->duplicateDecision,
                precedenceDecision: $r->precedenceDecision,
                collisionDecision: 'TARGET_PRICE_COLLISION',
                existingTargetDecision: $r->existingTargetDecision,
                zeroCostDecision: $r->zeroCostDecision,
                historyDecision: $r->historyDecision,
                readiness: Phase133BaseCostDryRunResolution::BLOCKED,
                reasons: array_values(array_unique(array_merge($r->reasons, ['TARGET_PRICE_COLLISION']))),
                trace: array_merge($r->trace, ['collision=TARGET_PRICE_COLLISION']),
            );
        }

        return $out;
    }

    private function finalizeReadiness(
        Phase133BaseCostDryRunResolution $r,
        Phase133DryRunCatalogueSnapshot $snapshot,
    ): Phase133BaseCostDryRunResolution {
        if ($r->readiness === Phase133BaseCostDryRunResolution::SKIPPED
            || $r->readiness === Phase133BaseCostDryRunResolution::BLOCKED
        ) {
            return $this->withExisting($r, $snapshot);
        }

        $zero = $this->policy->zeroCostDecision($r->baseCost);
        $readiness = $r->readiness;
        $reasons = $r->reasons;

        if ($r->priceListCode === null || $r->currencyCode === null) {
            $readiness = Phase133BaseCostDryRunResolution::BLOCKED;
            $reasons[] = 'MISSING_REQUIRED_REFERENCE';
        } elseif (!$this->dbcoContract->isApprovedForDryRun($r->dbco)) {
            $readiness = Phase133BaseCostDryRunResolution::BLOCKED;
            $reasons[] = 'UNKNOWN_DBCO';
        }

        if ($zero === LegacyPriceImportPolicy::ZERO_REVIEW) {
            $readiness = Phase133BaseCostDryRunResolution::REVIEW;
            $reasons[] = 'ZERO_COST';
        } elseif ($zero === LegacyPriceImportPolicy::ZERO_DATA_ERROR) {
            $readiness = Phase133BaseCostDryRunResolution::BLOCKED;
            if (!is_numeric($r->baseCost) || $r->baseCost === '') {
                $reasons[] = 'INVALID_COST';
            } else {
                $reasons[] = 'NEGATIVE_COST';
            }
        }

        $withExisting = $this->withExisting(
            new Phase133BaseCostDryRunResolution(
                sourceRowIds: $r->sourceRowIds,
                legacyCode: $r->legacyCode,
                normalizedCode: $r->normalizedCode,
                dbco: $r->dbco,
                identityType: $r->identityType,
                productId: $r->productId,
                productVariantId: $r->productVariantId,
                productVariantIds: $r->productVariantIds,
                priceListCode: $r->priceListCode,
                priceListId: $r->priceListId,
                referenceResolution: $r->referenceResolution,
                currencyCode: $r->currencyCode,
                currencyId: $r->currencyId,
                baseCost: $r->baseCost,
                effectiveFrom: $r->effectiveFrom,
                effectiveTo: $r->effectiveTo,
                duplicateDecision: $r->duplicateDecision,
                precedenceDecision: $r->precedenceDecision,
                collisionDecision: $r->collisionDecision,
                existingTargetDecision: $r->existingTargetDecision,
                zeroCostDecision: $zero,
                historyDecision: $r->historyDecision,
                readiness: $readiness,
                reasons: array_values(array_unique($reasons)),
                trace: $r->trace,
            ),
            $snapshot,
        );

        if ($withExisting->existingTargetDecision === Phase133BaseCostDryRunResolution::EXISTING_MANUAL
            && $withExisting->readiness === Phase133BaseCostDryRunResolution::READY
        ) {
            return new Phase133BaseCostDryRunResolution(
                sourceRowIds: $withExisting->sourceRowIds,
                legacyCode: $withExisting->legacyCode,
                normalizedCode: $withExisting->normalizedCode,
                dbco: $withExisting->dbco,
                identityType: $withExisting->identityType,
                productId: $withExisting->productId,
                productVariantId: $withExisting->productVariantId,
                productVariantIds: $withExisting->productVariantIds,
                priceListCode: $withExisting->priceListCode,
                priceListId: $withExisting->priceListId,
                referenceResolution: $withExisting->referenceResolution,
                currencyCode: $withExisting->currencyCode,
                currencyId: $withExisting->currencyId,
                baseCost: $withExisting->baseCost,
                effectiveFrom: $withExisting->effectiveFrom,
                effectiveTo: $withExisting->effectiveTo,
                duplicateDecision: $withExisting->duplicateDecision,
                precedenceDecision: $withExisting->precedenceDecision,
                collisionDecision: $withExisting->collisionDecision,
                existingTargetDecision: $withExisting->existingTargetDecision,
                zeroCostDecision: $withExisting->zeroCostDecision,
                historyDecision: $withExisting->historyDecision,
                readiness: Phase133BaseCostDryRunResolution::REVIEW,
                reasons: array_values(array_unique(array_merge($withExisting->reasons, ['MANUAL_DATA_CONFLICT']))),
                trace: $withExisting->trace,
            );
        }

        return $withExisting;
    }

    private function withExisting(
        Phase133BaseCostDryRunResolution $r,
        Phase133DryRunCatalogueSnapshot $snapshot,
    ): Phase133BaseCostDryRunResolution {
        if ($r->productVariantId === null || $r->priceListCode === null) {
            return $r;
        }
        $key = $r->productVariantId . '|' . $r->priceListCode;
        $existing = $snapshot->existingPrices[$key] ?? null;
        if ($existing === null) {
            return new Phase133BaseCostDryRunResolution(
                sourceRowIds: $r->sourceRowIds,
                legacyCode: $r->legacyCode,
                normalizedCode: $r->normalizedCode,
                dbco: $r->dbco,
                identityType: $r->identityType,
                productId: $r->productId,
                productVariantId: $r->productVariantId,
                productVariantIds: $r->productVariantIds,
                priceListCode: $r->priceListCode,
                priceListId: $r->priceListId,
                referenceResolution: $r->referenceResolution,
                currencyCode: $r->currencyCode,
                currencyId: $r->currencyId,
                baseCost: $r->baseCost,
                effectiveFrom: $r->effectiveFrom,
                effectiveTo: $r->effectiveTo,
                duplicateDecision: $r->duplicateDecision,
                precedenceDecision: $r->precedenceDecision,
                collisionDecision: $r->collisionDecision,
                existingTargetDecision: Phase133BaseCostDryRunResolution::EXISTING_CREATE,
                zeroCostDecision: $r->zeroCostDecision,
                historyDecision: $r->historyDecision,
                readiness: $r->readiness,
                reasons: $r->reasons,
                trace: $r->trace,
            );
        }

        $source = strtolower((string) ($existing['source'] ?? ''));
        $sameCost = is_numeric($existing['baseCost']) && is_numeric($r->baseCost)
            && bccomp((string) $existing['baseCost'], $r->baseCost, 6) === 0;
        $decision = $sameCost
            ? Phase133BaseCostDryRunResolution::EXISTING_NO_CHANGE
            : Phase133BaseCostDryRunResolution::EXISTING_UPDATE;
        if ($source !== '' && !str_contains($source, 'legacy')) {
            $decision = Phase133BaseCostDryRunResolution::EXISTING_MANUAL;
        }

        return new Phase133BaseCostDryRunResolution(
            sourceRowIds: $r->sourceRowIds,
            legacyCode: $r->legacyCode,
            normalizedCode: $r->normalizedCode,
            dbco: $r->dbco,
            identityType: $r->identityType,
            productId: $r->productId,
            productVariantId: $r->productVariantId,
            productVariantIds: $r->productVariantIds,
            priceListCode: $r->priceListCode,
            priceListId: $r->priceListId,
            referenceResolution: $r->referenceResolution,
            currencyCode: $r->currencyCode,
            currencyId: $r->currencyId,
            baseCost: $r->baseCost,
            effectiveFrom: $r->effectiveFrom,
            effectiveTo: $r->effectiveTo,
            duplicateDecision: $r->duplicateDecision,
            precedenceDecision: $r->precedenceDecision,
            collisionDecision: $r->collisionDecision,
            existingTargetDecision: $decision,
            zeroCostDecision: $r->zeroCostDecision,
            historyDecision: $r->historyDecision,
            readiness: $r->readiness,
            reasons: $r->reasons,
            trace: array_merge($r->trace, ['existing=' . $decision]),
        );
    }

    /**
     * @param list<string> $sourceRowIds
     * @param list<string> $costs
     */
    private function blockedSource(
        array $sourceRowIds,
        string $legacyCode,
        string $normalizedCode,
        int $dbco,
        Phase133DryRunCatalogueSnapshot $snapshot,
        string $reason,
        string $dupDecision,
        array $costs,
    ): Phase133BaseCostDryRunResolution {
        $mapping = $this->dbcoContract->forDbco($dbco);
        $priceListCode = $mapping['priceListCode'] ?? null;
        $plMeta = $priceListCode !== null ? ($snapshot->priceListsByCode[$priceListCode] ?? null) : null;

        return $this->makeResolution(
            sourceRowIds: $sourceRowIds,
            record: new LegacyPriceRecord(
                sourceRowId: implode(',', $sourceRowIds),
                code: $legacyCode,
                normalizedCode: $normalizedCode,
                dbco: $dbco,
                cost: $costs[0] ?? '',
                dc: null,
                br: null,
                uwt: null,
                uwtcu: null,
                setup: null,
            ),
            identityType: PriceImportResolution::IDENTITY_UNRESOLVED,
            productId: null,
            productVariantId: null,
            productVariantIds: [],
            priceListCode: $priceListCode,
            priceListId: $plMeta['priceListId'] ?? null,
            currencyCode: $mapping['currencyCode'] ?? null,
            currencyId: $plMeta['currencyId'] ?? null,
            referenceResolution: $snapshot->referenceResolutionMode,
            duplicateDecision: $dupDecision,
            precedenceDecision: 'NONE',
            collisionDecision: 'NONE',
            existingTargetDecision: Phase133BaseCostDryRunResolution::EXISTING_NONE,
            readiness: Phase133BaseCostDryRunResolution::BLOCKED,
            reasons: [$reason],
            trace: ['blockedSource=' . $reason],
        );
    }

    /**
     * @param list<string> $sourceRowIds
     * @param list<string> $productVariantIds
     * @param list<string> $reasons
     * @param list<string> $trace
     */
    private function makeResolution(
        array $sourceRowIds,
        LegacyPriceRecord $record,
        string $identityType,
        ?string $productId,
        ?string $productVariantId,
        array $productVariantIds,
        ?string $priceListCode,
        ?string $priceListId,
        ?string $currencyCode,
        ?string $currencyId,
        string $referenceResolution,
        string $duplicateDecision,
        string $precedenceDecision,
        string $collisionDecision,
        string $existingTargetDecision,
        string $readiness,
        array $reasons,
        array $trace,
    ): Phase133BaseCostDryRunResolution {
        return new Phase133BaseCostDryRunResolution(
            sourceRowIds: $sourceRowIds,
            legacyCode: $record->code,
            normalizedCode: $record->normalizedCode,
            dbco: $record->dbco,
            identityType: $identityType,
            productId: $productId,
            productVariantId: $productVariantId,
            productVariantIds: $productVariantIds,
            priceListCode: $priceListCode,
            priceListId: $priceListId,
            referenceResolution: $referenceResolution,
            currencyCode: $currencyCode,
            currencyId: $currencyId,
            baseCost: $record->cost,
            effectiveFrom: $this->policy->utcFromLegacyDate($record->dc),
            effectiveTo: null,
            duplicateDecision: $duplicateDecision,
            precedenceDecision: $precedenceDecision,
            collisionDecision: $collisionDecision,
            existingTargetDecision: $existingTargetDecision,
            zeroCostDecision: $this->policy->zeroCostDecision(is_numeric($record->cost) ? $record->cost : 'x'),
            historyDecision: LegacyPriceImportPolicy::APPROVED_HISTORY_POLICY,
            readiness: $readiness,
            reasons: $reasons,
            trace: $trace,
        );
    }

    /**
     * @param list<Phase133BaseCostDryRunResolution> $resolutions
     * @param callable(Phase133BaseCostDryRunResolution): bool $filter
     * @return list<array<string, mixed>>
     */
    private function sampleByReadiness(array $resolutions, string $readiness, int $limit, callable $filter): array
    {
        $out = [];
        foreach ($resolutions as $r) {
            if ($r->readiness !== $readiness && $readiness !== '*') {
                // allow filter-only when readiness is wildcard via filter
            }
            if (!$filter($r)) {
                continue;
            }
            $row = $r->toArray();
            // Sanitise: keep structure but mask absolute cost in public samples as hash of presence
            $row['baseCostPresent'] = $r->baseCost !== '';
            unset($row['baseCost']);
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<Phase133BaseCostDryRunResolution> $resolutions
     * @param array<string, int> $sourceCounters
     * @param array<string, int> $latestStats
     * @return array<string, mixed>
     */
    private function summarize(
        array $resolutions,
        array $sourceCounters,
        array $latestStats,
        int $exact,
        int $explicit,
        int $maskSource,
        int $maskDerived,
        int $unresolved,
        int $unresolvedNotMaterialized,
        int $ambiguous,
        Phase133DryRunCatalogueSnapshot $snapshot,
    ): array {
        $ready = $review = $blocked = $skipped = 0;
        $tca = $tcus = 0;
        $positive = $zero = $negative = $missing = $invalid = 0;
        $zero2507 = $zero2517 = 0;
        $create = $noChange = $update = $manual = 0;
        $exactOverMask = 0;
        $maskCollisions = 0;
        $targetCollision = 0;
        $differingSameDate = 0;

        $variantStatus = [];
        foreach ($resolutions as $r) {
            match ($r->readiness) {
                Phase133BaseCostDryRunResolution::READY => ++$ready,
                Phase133BaseCostDryRunResolution::REVIEW => ++$review,
                Phase133BaseCostDryRunResolution::BLOCKED => ++$blocked,
                default => ++$skipped,
            };
            if ($r->priceListCode === 'TCA-BASE') {
                ++$tca;
            }
            if ($r->priceListCode === 'TCUS-BASE') {
                ++$tcus;
            }
            if ($r->precedenceDecision === 'MASK_SHADOWED_BY_EXACT') {
                ++$exactOverMask;
            }
            if ($r->collisionDecision === 'TARGET_PRICE_COLLISION') {
                ++$targetCollision;
            }
            if (in_array('DIFFERING_SAME_DATE_COST', $r->reasons, true)) {
                ++$differingSameDate;
            }
            if (is_numeric($r->baseCost)) {
                if (bccomp($r->baseCost, '0', 6) > 0) {
                    ++$positive;
                } elseif (bccomp($r->baseCost, '0', 6) === 0) {
                    ++$zero;
                    if ($r->dbco === 2507) {
                        ++$zero2507;
                    }
                    if ($r->dbco === 2517) {
                        ++$zero2517;
                    }
                } else {
                    ++$negative;
                }
            } elseif ($r->baseCost === '') {
                ++$missing;
            } else {
                ++$invalid;
            }
            match ($r->existingTargetDecision) {
                Phase133BaseCostDryRunResolution::EXISTING_CREATE => ++$create,
                Phase133BaseCostDryRunResolution::EXISTING_NO_CHANGE => ++$noChange,
                Phase133BaseCostDryRunResolution::EXISTING_UPDATE => ++$update,
                Phase133BaseCostDryRunResolution::EXISTING_MANUAL => ++$manual,
                default => null,
            };

            if ($r->productVariantId !== null) {
                $vid = $r->productVariantId;
                $prev = $variantStatus[$vid] ?? 'NONE';
                $rank = ['NONE' => 0, 'SKIPPED' => 1, 'BLOCKED' => 2, 'REVIEW' => 3, 'READY' => 4];
                if (($rank[$r->readiness] ?? 0) > ($rank[$prev] ?? 0)) {
                    $variantStatus[$vid] = $r->readiness;
                }
            }
        }

        $azureTotal = count($snapshot->variants);
        $withReady = $withReview = $withBlocked = $withNone = 0;
        foreach ($snapshot->variants as $v) {
            $st = $variantStatus[(string) $v['productVariantId']] ?? 'NONE';
            match ($st) {
                'READY' => ++$withReady,
                'REVIEW' => ++$withReview,
                'BLOCKED' => ++$withBlocked,
                default => ++$withNone,
            };
        }
        $withCandidate = $withReady + $withReview + $withBlocked;
        $coverage = $azureTotal > 0 ? round(100 * $withCandidate / $azureTotal, 2) : 0.0;

        // Cost distribution for READY+REVIEW (sanitised stats only)
        $costsByList = ['TCA-BASE' => [], 'TCUS-BASE' => []];
        foreach ($resolutions as $r) {
            if (!in_array($r->readiness, [Phase133BaseCostDryRunResolution::READY, Phase133BaseCostDryRunResolution::REVIEW], true)) {
                continue;
            }
            if (!is_numeric($r->baseCost) || bccomp($r->baseCost, '0', 6) <= 0) {
                continue;
            }
            if ($r->priceListCode === 'TCA-BASE' || $r->priceListCode === 'TCUS-BASE') {
                $costsByList[$r->priceListCode][] = (float) $r->baseCost;
            }
        }

        return [
            'scope' => [
                'included' => ['2507/TCA/AUD', '2517/TCUS/USD'],
                'excluded' => ['2519/TCASIA'],
            ],
            'source' => $sourceCounters + $latestStats,
            'identity' => [
                'exactResolutions' => $exact,
                'explicitResolutions' => $explicit,
                'maskSourceRecords' => $maskSource,
                'maskDerivedVariantResolutions' => $maskDerived,
                'unresolved' => $unresolved,
                'unresolvedMaterializedSample' => max(0, $unresolved - $unresolvedNotMaterialized),
                'unresolvedNotMaterialized' => $unresolvedNotMaterialized,
                'ambiguous' => $ambiguous,
                'exactOverMaskOverrides' => $exactOverMask,
            ],
            'targetCoverage' => [
                'azureVariants' => $azureTotal,
                'withPriceCandidate' => $withCandidate,
                'READY' => $withReady,
                'REVIEW' => $withReview,
                'BLOCKED' => $withBlocked,
                'noCandidate' => $withNone,
                'coveragePct' => $coverage,
                'inScopePricingIdentityCoverage' => $coverage,
            ],
            'priceContext' => [
                'TCA-BASE' => $tca,
                'TCUS-BASE' => $tcus,
            ],
            'costQuality' => [
                'positive' => $positive,
                'zero' => $zero,
                'zeroByDbco' => ['2507' => $zero2507, '2517' => $zero2517],
                'negative' => $negative,
                'missing' => $missing,
                'invalid' => $invalid,
            ],
            'existingTarget' => [
                'CREATE_CANDIDATE' => $create,
                'NO_CHANGE' => $noChange,
                'UPDATE_OR_REVISION_CANDIDATE' => $update,
                'MANUAL_DATA_CONFLICT' => $manual,
            ],
            'collisions' => [
                'TARGET_PRICE_COLLISION' => $targetCollision,
                'DIFFERING_SAME_DATE_COST' => $differingSameDate,
                'maskCollisions' => $maskCollisions,
            ],
            'final' => [
                'READY' => $ready,
                'REVIEW' => $review,
                'BLOCKED' => $blocked,
                'SKIPPED' => $skipped + $unresolvedNotMaterialized,
            ],
            'costDistribution' => [
                'TCA-BASE' => $this->costStats($costsByList['TCA-BASE']),
                'TCUS-BASE' => $this->costStats($costsByList['TCUS-BASE']),
            ],
            'writes' => [
                'WordPress' => 0,
                'LegacyTc' => 0,
                'ProductPrices' => 0,
                'ProductVariantPrices' => 0,
                'AzurePricingTotal' => 0,
                'Blob' => 0,
                'ForbiddenWriteAttempts' => 0,
            ],
        ];
    }

    /**
     * @param list<float> $values
     * @return array<string, float|null>
     */
    private function costStats(array $values): array
    {
        if ($values === []) {
            return ['min' => null, 'max' => null, 'median' => null, 'p95' => null, 'n' => 0];
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        $median = $n % 2 === 1 ? $values[$mid] : (($values[$mid - 1] + $values[$mid]) / 2);
        $p95Index = (int) max(0, min($n - 1, (int) floor(($n - 1) * 0.95)));

        return [
            'min' => $values[0],
            'max' => $values[$n - 1],
            'median' => $median,
            'p95' => $values[$p95Index],
            'n' => $n,
        ];
    }
}
