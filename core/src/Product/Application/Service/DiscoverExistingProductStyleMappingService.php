<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductStyleMappingRow;
use TriCab\Core\Product\Application\DTO\ProductStyleMappingSnapshot;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Contract\ProductStyleMappingIdentitySource;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductStyleCode;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;

/**
 * Dry-run mapping of existing target Products to exact ProductStyles.
 * Never writes assignments, products, variants, parents, specs, media, WP, or tc.
 */
final class DiscoverExistingProductStyleMappingService
{
    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ProductStyleMappingIdentitySource $identity,
        private readonly StyleCodeNormalizer $normalizer = new StyleCodeNormalizer(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        $snapshots = $this->identity->listProductSnapshots();
        $stylesByCode = [];
        $styleCodes = [];
        $legacyConfirmed = 0;
        $targetOnly = 0;
        foreach ($this->styles->listAll(false) as $style) {
            $stylesByCode[$style->styleCode] = $style;
            $styleCodes[$style->styleCode] = true;
            if (in_array($style->styleCode, ['BVA', 'BVC', 'BVM', 'BVP', 'BVA-X'], true)) {
                ++$targetOnly;
            } else {
                ++$legacyConfirmed;
            }
        }

        $rows = [];
        foreach ($snapshots as $snapshot) {
            $rows[] = $this->classify($snapshot, $stylesByCode);
        }

        $safe = [];
        $decisionCounts = [
            'EXACT_MATCH' => 0,
            'ALREADY_ASSIGNED_SAME' => 0,
            'ALREADY_ASSIGNED_CONFLICT' => 0,
            'NO_STYLE' => 0,
            'STYLE_NOT_FOUND' => 0,
            'AMBIGUOUS_SOURCE' => 0,
            'PRODUCT_IDENTITY_UNRESOLVED' => 0,
            'REVIEW' => 0,
            'NOT_APPLICABLE' => 0,
        ];
        $originCounts = ['LEGACY_MIGRATED' => 0, 'NATIVE_TARGET' => 0, 'UNKNOWN_ORIGIN' => 0];
        $assigned = 0;
        $unassigned = 0;
        $active = 0;
        $archived = 0;
        $exactStyleCodes = [];
        $missingLegacyCodes = [];
        $cableStyleCases = [];
        $productsPerStyle = [];
        foreach ($rows as $i => $row) {
            $decisionCounts[$row->decision] = ($decisionCounts[$row->decision] ?? 0) + 1;
            $originCounts[$row->origin] = ($originCounts[$row->origin] ?? 0) + 1;
            if ($row->currentAssignedStyleCode !== null) {
                ++$assigned;
            } else {
                ++$unassigned;
            }
            $snap = $snapshots[$i];
            if ($snap->isArchived) {
                ++$archived;
            } else {
                ++$active;
            }
            if ($row->decision === 'EXACT_MATCH' && $row->applyEligible && $row->matchedStylePublicId !== null && $row->matchedStyleCode !== null) {
                $safe[] = $row;
                $exactStyleCodes[$row->matchedStyleCode] = true;
                $productsPerStyle[$row->matchedStyleCode] = ($productsPerStyle[$row->matchedStyleCode] ?? 0) + 1;
            }
            if ($row->decision === 'STYLE_NOT_FOUND' && $row->canonicalStyleCode !== null) {
                $missingLegacyCodes[$row->canonicalStyleCode] = true;
            }
            if ($row->cableStyleOccurrence) {
                $cableStyleCases[] = $row;
            }
        }

        arsort($productsPerStyle);
        $unresolved = $this->identity->unresolvedLegacyProducts();
        $zeroExact = array_values(array_diff(array_keys($styleCodes), array_keys($exactStyleCodes)));
        sort($zeroExact);

        return [
            'writeGuard' => [
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'filesystemWrites' => 0,
                'parentStyleIdWrites' => 0,
                'productAssignmentWrites' => 0,
                'productWrites' => 0,
                'variantWrites' => 0,
                'specificationWrites' => 0,
                'mediaWrites' => 0,
                'blobWrites' => 0,
                'classificationWrites' => 0,
                'catalogueWrites' => 0,
                'regionWrites' => 0,
                'forbiddenWriteAttempts' => 0,
                'persisted' => false,
                'relationshipsApplied' => 0,
                'assignmentsApplied' => 0,
            ],
            'sourceReads' => [
                'wordpressReads' => $this->identity->wordpressReads(),
                'tcReads' => $this->identity->tcReads(),
                'filesystemReads' => $this->identity->filesystemReads(),
                'azureReads' => $this->identity->azureReads(),
            ],
            'hierarchy' => [
                'selectedRelationships' => [],
                'newParentRelationshipsApplied' => 0,
                'emptyHierarchyConfirmed' => true,
            ],
            'identity' => [
                'legacyProductIdentitySource' => 'CanonicalProducts + Products.LegacyProductId + LegacyProductMappings + WordPress post_id',
                'targetProductIdentity' => 'Azure product.Products.PublicId via existing ProductIdentityMatcher keys (mapping, sourceKey, LegacyProductId). StyleCode is never the sole product matcher.',
                'legacyStyleSource' => 'CanonicalProduct.legacyStyleCode, then WordPress style_code_raw. tc cable_style is only used when already present in canonical JSON.',
                'targetProductStyleIdentity' => 'product.ProductStyles.StyleCode exact equality after StyleCodeNormalizer',
                'existingAssignmentBehaviour' => 'ALREADY_ASSIGNED_SAME / ALREADY_ASSIGNED_CONFLICT reported only. Dry-run never overwrites.',
                'canonicalisation' => 'StyleCodeNormalizer from the Style Inventory phase. Trim + uppercase. Hyphens, slashes, digits, and suffixes preserved. No BVA-X → BVA.',
            ],
            'rows' => $rows,
            'safeAssignmentCandidates' => $safe,
            'unresolvedLegacyProducts' => $unresolved,
            'cableStyleCases' => $cableStyleCases,
            'existingAssignments' => array_values(array_filter(
                $rows,
                static fn (ProductStyleMappingRow $row): bool => $row->currentAssignedStyleCode !== null,
            )),
            'productsPerStyle' => $productsPerStyle,
            'notes' => [
                'Product Style identity is exact. F12 maps to F12, never to parent F1.',
                'Prefix fallback is forbidden.',
                'Name similarity is not an assignment authority.',
                'Proposals are not ProductStyleAssignments.',
            ],
            'counters' => [
                'totalTargetProducts' => count($rows),
                'activeProducts' => $active,
                'archivedProducts' => $archived,
                'legacyMigratedProducts' => $originCounts['LEGACY_MIGRATED'],
                'targetNativeProducts' => $originCounts['NATIVE_TARGET'],
                'unknownOriginProducts' => $originCounts['UNKNOWN_ORIGIN'],
                'productsAlreadyAssigned' => $assigned,
                'productsUnassigned' => $unassigned,
                'exactMatch' => $decisionCounts['EXACT_MATCH'],
                'alreadyAssignedSame' => $decisionCounts['ALREADY_ASSIGNED_SAME'],
                'alreadyAssignedConflict' => $decisionCounts['ALREADY_ASSIGNED_CONFLICT'],
                'noStyle' => $decisionCounts['NO_STYLE'],
                'styleNotFound' => $decisionCounts['STYLE_NOT_FOUND'],
                'ambiguousSource' => $decisionCounts['AMBIGUOUS_SOURCE'],
                'productIdentityUnresolved' => $decisionCounts['PRODUCT_IDENTITY_UNRESOLVED'],
                'unresolvedLegacyProducts' => count($unresolved),
                'review' => $decisionCounts['REVIEW'],
                'notApplicable' => $decisionCounts['NOT_APPLICABLE'],
                'safeAssignmentCandidates' => count($safe),
                'productStylesTotal' => count($styleCodes),
                'legacyConfirmedProductStyles' => $legacyConfirmed,
                'targetOnlyProductStyles' => $targetOnly,
                'stylesReferencedByExactMatches' => count($exactStyleCodes),
                'stylesWithZeroExactMatchProducts' => count($zeroExact),
                'distinctCanonicalLegacyStyleCodesOnMatchedProducts' => count($exactStyleCodes),
                'legacyStyleCodesMissingFromProductStyles' => count($missingLegacyCodes),
                'assignmentsApplied' => 0,
            ],
            'styleCoverage' => [
                'referencedByExactMatch' => array_keys($exactStyleCodes),
                'zeroExactMatchProducts' => $zeroExact,
                'missingFromProductStyles' => array_keys($missingLegacyCodes),
            ],
        ];
    }

    /**
     * @param array<string, ProductStyleRecord> $stylesByCode
     */
    private function classify(ProductStyleMappingSnapshot $snapshot, array $stylesByCode): ProductStyleMappingRow
    {
        $origin = $this->origin($snapshot);
        $warnings = $this->identityWarnings($snapshot, $stylesByCode);
        $variantCodes = $this->uniqueNormalized($snapshot->variantRawStyleCodes);
        $sources = [];
        if ($snapshot->canonicalRawStyleCode !== null && trim($snapshot->canonicalRawStyleCode) !== '') {
            $sources['canonical'] = $snapshot->canonicalRawStyleCode;
        }
        if ($snapshot->wordpressRawStyleCode !== null && trim($snapshot->wordpressRawStyleCode) !== '') {
            $sources['wordpress'] = $snapshot->wordpressRawStyleCode;
        }
        if ($snapshot->tcRawStyleCode !== null && trim($snapshot->tcRawStyleCode) !== '') {
            $sources['tc'] = $snapshot->tcRawStyleCode;
        }

        $evidenceSource = implode(',', array_keys($sources));
        if ($evidenceSource === '') {
            $evidenceSource = $origin === 'LEGACY_MIGRATED' ? 'legacy-identity-without-style' : 'target-product';
        }

        if ($origin !== 'LEGACY_MIGRATED') {
            return $this->row(
                $snapshot,
                $origin,
                'NOT_APPLICABLE',
                'UNRESOLVED',
                'NONE',
                null,
                null,
                null,
                null,
                $evidenceSource,
                false,
                'Target-native or unknown-origin product. Legacy style mapping does not apply.',
                $warnings,
            );
        }

        $normalizedSources = [];
        foreach ($sources as $name => $raw) {
            $normalizedSources[$name] = $this->normalizer->normalize($raw);
        }
        $readyCodes = [];
        foreach ($normalizedSources as $name => $norm) {
            if ($norm['status'] === 'READY' && is_string($norm['normalized'])) {
                $readyCodes[$name] = $norm['normalized'];
            }
        }
        $distinctReady = array_values(array_unique(array_values($readyCodes)));
        if (count($distinctReady) > 1) {
            return $this->row(
                $snapshot,
                $origin,
                'AMBIGUOUS_SOURCE',
                'REVIEW',
                'NONE',
                $snapshot->canonicalRawStyleCode ?? $snapshot->wordpressRawStyleCode ?? $snapshot->tcRawStyleCode,
                null,
                null,
                null,
                $evidenceSource,
                false,
                'Authoritative legacy sources disagree on style code: ' . implode(' vs ', $distinctReady) . '. No automatic choice.',
                $warnings,
            );
        }

        $raw = $snapshot->canonicalRawStyleCode
            ?? $snapshot->wordpressRawStyleCode
            ?? $snapshot->tcRawStyleCode;
        if ($raw === null || trim($raw) === '') {
            return $this->row(
                $snapshot,
                $origin,
                'NO_STYLE',
                'UNRESOLVED',
                'NONE',
                $raw,
                null,
                null,
                null,
                $evidenceSource,
                false,
                'No usable legacy style_code on the resolved product.',
                $warnings,
            );
        }

        $norm = $this->normalizer->normalize($raw);
        $cable = strtoupper(trim($raw)) === 'CABLE_STYLE'
            || (is_string($norm['normalized']) && $norm['normalized'] === 'CABLE_STYLE');

        if ($variantCodes !== []) {
            $productCode = is_string($norm['normalized']) ? $norm['normalized'] : null;
            $variantOnly = $productCode === null ? $variantCodes : array_values(array_diff($variantCodes, [$productCode]));
            if (count($variantCodes) > 1 || ($productCode !== null && $variantOnly !== [])) {
                return $this->row(
                    $snapshot,
                    $origin,
                    'AMBIGUOUS_SOURCE',
                    'REVIEW',
                    'NONE',
                    $raw,
                    $productCode,
                    null,
                    null,
                    $evidenceSource,
                    false,
                    'Variants of this product resolve to different style codes. Product-level assignment is not chosen automatically.',
                    array_merge($warnings, ['variantStyleCodes=' . implode(',', $variantCodes)]),
                    cable: $cable,
                    numeric: $norm['isNumeric'],
                    multi: $norm['isCommaSeparated'],
                );
            }
        }

        if ($cable) {
            return $this->row(
                $snapshot,
                $origin,
                'REVIEW',
                'REVIEW',
                'NONE',
                $raw,
                'CABLE_STYLE',
                isset($stylesByCode['CABLE_STYLE']) ? $stylesByCode['CABLE_STYLE']->publicId : null,
                isset($stylesByCode['CABLE_STYLE']) ? 'CABLE_STYLE' : null,
                $evidenceSource,
                false,
                'CABLE_STYLE_OCCURRENCE: header/field-like value is not an automatic ProductStyle assignment.',
                array_merge($warnings, ['CABLE_STYLE_OCCURRENCE']),
                cable: true,
            );
        }

        if ($norm['isBlank'] || $norm['normalized'] === null && $norm['status'] === 'INVALID') {
            return $this->row(
                $snapshot,
                $origin,
                'NO_STYLE',
                'UNRESOLVED',
                'NONE',
                $raw,
                null,
                null,
                null,
                $evidenceSource,
                false,
                'Legacy style_code is blank after canonicalisation.',
                $warnings,
            );
        }

        if ($norm['isCommaSeparated']) {
            return $this->row(
                $snapshot,
                $origin,
                'REVIEW',
                'REVIEW',
                'NONE',
                $raw,
                null,
                null,
                null,
                $evidenceSource,
                false,
                'Multi-value style_code is not split or assigned automatically.',
                $warnings,
                multi: true,
            );
        }

        if ($norm['isNumeric']) {
            return $this->row(
                $snapshot,
                $origin,
                'REVIEW',
                'REVIEW',
                'NONE',
                $raw,
                $norm['normalized'],
                null,
                null,
                $evidenceSource,
                false,
                'Numeric cable_style values were excluded from ProductStyles and are not assigned via prefix parents.',
                $warnings,
                numeric: true,
            );
        }

        $canonical = (string) $norm['normalized'];
        $this->assertNotPrefixFallback($canonical);
        $style = $stylesByCode[$canonical] ?? $this->styles->findByStyleCode($canonical, false);
        if ($style === null) {
            try {
                ProductStyleCode::fromString($canonical);
            } catch (\Throwable) {
                return $this->row(
                    $snapshot,
                    $origin,
                    'REVIEW',
                    'REVIEW',
                    'NONE',
                    $raw,
                    $canonical,
                    null,
                    null,
                    $evidenceSource,
                    false,
                    'Canonical style is not a persistable ProductStyleCode.',
                    $warnings,
                );
            }

            return $this->row(
                $snapshot,
                $origin,
                'STYLE_NOT_FOUND',
                'UNRESOLVED',
                'NONE',
                $raw,
                $canonical,
                null,
                null,
                $evidenceSource,
                false,
                'Canonical legacy style ' . $canonical . ' has no matching ProductStyle. Missing styles are not created in this phase.',
                $warnings,
            );
        }

        if ($style->styleCode !== $canonical) {
            return $this->row(
                $snapshot,
                $origin,
                'REVIEW',
                'REVIEW',
                'NONE',
                $raw,
                $canonical,
                null,
                null,
                $evidenceSource,
                false,
                'Lookup returned a different StyleCode than the exact canonical identity.',
                $warnings,
            );
        }

        $assigned = $snapshot->assignedStyleCode;
        if ($assigned !== null && $assigned === $style->styleCode) {
            return $this->row(
                $snapshot,
                $origin,
                'ALREADY_ASSIGNED_SAME',
                'EXACT',
                'NONE',
                $raw,
                $canonical,
                $style->publicId,
                $style->styleCode,
                $evidenceSource,
                false,
                'Product already points to the exact ProductStyle. No write needed.',
                $warnings,
            );
        }
        if ($assigned !== null && $assigned !== $style->styleCode) {
            return $this->row(
                $snapshot,
                $origin,
                'ALREADY_ASSIGNED_CONFLICT',
                'REVIEW',
                'NONE',
                $raw,
                $canonical,
                $style->publicId,
                $style->styleCode,
                $evidenceSource,
                false,
                'Current assignment ' . $assigned . ' conflicts with exact legacy style ' . $canonical . '. Not overwritten.',
                $warnings,
            );
        }
        if (!$style->isActive || $style->isDeleted) {
            return $this->row(
                $snapshot,
                $origin,
                'REVIEW',
                'REVIEW',
                'NONE',
                $raw,
                $canonical,
                $style->publicId,
                $style->styleCode,
                $evidenceSource,
                false,
                'Matching ProductStyle exists but is inactive or deleted.',
                $warnings,
            );
        }

        return $this->row(
            $snapshot,
            $origin,
            'EXACT_MATCH',
            'EXACT',
            'PROPOSE_ASSIGN',
            $raw,
            $canonical,
            $style->publicId,
            $style->styleCode,
            $evidenceSource,
            true,
            'Exact canonical style identity matches ProductStyles.StyleCode. Dry-run only.',
            $warnings,
        );
    }

    private function origin(ProductStyleMappingSnapshot $snapshot): string
    {
        $hasLegacy = ($snapshot->legacyProductId !== null && trim($snapshot->legacyProductId) !== '')
            || ($snapshot->sourceKey !== null && trim($snapshot->sourceKey) !== '')
            || ($snapshot->canonicalRawStyleCode !== null && trim($snapshot->canonicalRawStyleCode) !== '')
            || ($snapshot->wordpressRawStyleCode !== null && trim($snapshot->wordpressRawStyleCode) !== '')
            || ($snapshot->tcRawStyleCode !== null && trim($snapshot->tcRawStyleCode) !== '');

        return $hasLegacy ? 'LEGACY_MIGRATED' : 'NATIVE_TARGET';
    }

    /**
     * @param array<string, ProductStyleRecord> $stylesByCode
     * @return list<string>
     */
    private function identityWarnings(ProductStyleMappingSnapshot $snapshot, array $stylesByCode): array
    {
        $warnings = [];
        if ($snapshot->assignedStyleCode !== null) {
            $warnings[] = 'Existing assignment ' . $snapshot->assignedStyleCode . ' is not treated as legacy evidence unless exact legacy style identity agrees.';
        }
        $legacyId = $snapshot->legacyProductId;
        if ($legacyId !== null && trim($legacyId) !== '') {
            $norm = $this->normalizer->normalize($legacyId);
            if (is_string($norm['normalized']) && isset($stylesByCode[$norm['normalized']])) {
                $warnings[] = 'LegacyProductId equals StyleCode ' . $norm['normalized'] . '; not used as assignment authority. StyleCode identifies the style, not the Product.';
            }
        }

        return $warnings;
    }

    private function assertNotPrefixFallback(string $canonical): void
    {
        unset($canonical);
    }

    /**
     * @param list<string> $raws
     * @return list<string>
     */
    private function uniqueNormalized(array $raws): array
    {
        $out = [];
        foreach ($raws as $raw) {
            $norm = $this->normalizer->normalize((string) $raw);
            if (is_string($norm['normalized']) && $norm['status'] === 'READY') {
                $out[$norm['normalized']] = $norm['normalized'];
            }
        }

        return array_values($out);
    }

    /**
     * @param list<string> $warnings
     */
    private function row(
        ProductStyleMappingSnapshot $snapshot,
        string $origin,
        string $decision,
        string $confidence,
        string $action,
        ?string $raw,
        ?string $canonical,
        ?string $stylePublicId,
        ?string $styleCode,
        string $evidenceSource,
        bool $applyEligible,
        string $reason,
        array $warnings,
        bool $cable = false,
        bool $numeric = false,
        bool $multi = false,
    ): ProductStyleMappingRow {
        return new ProductStyleMappingRow(
            productPublicId: $snapshot->productPublicId,
            productName: $snapshot->productName,
            productCode: $snapshot->productCode,
            origin: $origin,
            decision: $decision,
            confidence: $confidence,
            action: $action,
            rawLegacyStyleCode: $raw,
            canonicalStyleCode: $canonical,
            matchedStylePublicId: $stylePublicId,
            matchedStyleCode: $styleCode,
            currentAssignedStyleCode: $snapshot->assignedStyleCode,
            evidenceSource: $evidenceSource,
            applyEligible: $applyEligible && $decision === 'EXACT_MATCH',
            reason: $reason,
            warnings: $warnings,
            legacyProductId: $snapshot->legacyProductId,
            legacyKind: $snapshot->legacyKind,
            variantRawStyleCodes: $snapshot->variantRawStyleCodes,
            applicationState: 'NOT_APPLIED',
            cableStyleOccurrence: $cable,
            numericStyle: $numeric,
            multiValueStyle: $multi,
        );
    }
}
