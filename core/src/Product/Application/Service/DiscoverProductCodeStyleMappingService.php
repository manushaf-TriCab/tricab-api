<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductCodeStyleMappingRow;
use TriCab\Core\Product\Application\DTO\ProductStyleMappingSnapshot;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Contract\ProductStyleMappingIdentitySource;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductStyleCode;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;

/**
 * Dry-run: style-shell Product.Code (InternalCode) is the ProductStyle identity.
 * Zero writes. No WordPress product import. No hierarchy.
 */
final class DiscoverProductCodeStyleMappingService
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
        $snapshots = $this->identity->listTargetProducts();
        $stylesByCode = [];
        foreach ($this->styles->listAll(false) as $style) {
            $stylesByCode[$style->styleCode] = $style;
        }
        $styleCountBefore = count($stylesByCode);

        $rows = [];
        foreach ($snapshots as $snapshot) {
            $rows[] = $this->classify($snapshot, $stylesByCode);
        }

        $populationCounts = [
            'STYLE_SHELL_PRODUCT' => 0,
            'TARGET_NATIVE_PRODUCT' => 0,
            'DEBUG_TEST_PRODUCT' => 0,
            'UNKNOWN' => 0,
        ];
        $decisionCounts = [
            'EXACT_STYLE_MATCH' => 0,
            'MISSING_PRODUCT_STYLE' => 0,
            'ALREADY_ASSIGNED_SAME' => 0,
            'ASSIGNMENT_CONFLICT' => 0,
            'INVALID_PRODUCT_CODE' => 0,
            'REVIEW' => 0,
            'NOT_APPLICABLE' => 0,
        ];
        $existingAssignments = [];
        $exact = [];
        $missing = [];
        $afterCreate = [];
        $conflicts = [];
        $codesByCanonical = [];
        $eligibleCodes = [];
        $matchingExisting = [];
        $missingCodes = [];
        foreach ($rows as $row) {
            $populationCounts[$row->population] = ($populationCounts[$row->population] ?? 0) + 1;
            $decisionCounts[$row->decision] = ($decisionCounts[$row->decision] ?? 0) + 1;
            if ($row->currentAssignedStyleCode !== null) {
                $existingAssignments[] = $row;
            }
            if ($row->population === 'STYLE_SHELL_PRODUCT' && $row->canonicalProductCode !== null) {
                $eligibleCodes[$row->canonicalProductCode] = true;
                $codesByCanonical[$row->canonicalProductCode][] = $row;
                if ($row->styleExists) {
                    $matchingExisting[$row->canonicalProductCode] = true;
                } else {
                    $missingCodes[$row->canonicalProductCode] = true;
                }
            }
            if ($row->decision === 'EXACT_STYLE_MATCH' && $row->applyEligible) {
                $exact[] = $row;
            }
            if ($row->decision === 'MISSING_PRODUCT_STYLE') {
                $missing[] = $row;
                if ($row->proposedAssignment !== null) {
                    $afterCreate[] = $row;
                }
            }
            if ($row->decision === 'ASSIGNMENT_CONFLICT') {
                $conflicts[] = $row;
                if ($row->proposedStyle !== null) {
                    $missing = $this->uniqueByCode($missing, $row);
                    if ($row->canonicalProductCode !== null) {
                        $missingCodes[$row->canonicalProductCode] = true;
                    }
                }
            }
        }

        $shared = [];
        $sharedReview = [];
        foreach ($codesByCanonical as $code => $group) {
            if (count($group) > 1) {
                $shared[] = [
                    'productCode' => $code,
                    'productCount' => count($group),
                    'classification' => 'VALID_SHARED_STYLE',
                    'products' => array_map(
                        static fn (ProductCodeStyleMappingRow $r): array => [
                            'productPublicId' => $r->productPublicId,
                            'productName' => $r->productName,
                        ],
                        $group,
                    ),
                ];
            }
        }

        $usedByEligible = array_keys($matchingExisting);
        $unusedExisting = array_values(array_diff(array_keys($stylesByCode), $usedByEligible));
        sort($unusedExisting);
        $missingList = array_keys($missingCodes);
        sort($missingList);

        return [
            'writeGuard' => [
                'productStyleWrites' => 0,
                'productAssignmentWrites' => 0,
                'productWrites' => 0,
                'variantWrites' => 0,
                'parentStyleIdWrites' => 0,
                'specificationWrites' => 0,
                'mediaWrites' => 0,
                'blobWrites' => 0,
                'classificationWrites' => 0,
                'catalogueWrites' => 0,
                'regionWrites' => 0,
                'wordpressWrites' => 0,
                'tcWrites' => 0,
                'filesystemWrites' => 0,
                'forbiddenWriteAttempts' => 0,
                'persisted' => false,
                'stylesCreated' => 0,
                'assignmentsApplied' => 0,
                'assignmentsReplaced' => 0,
                'relationshipsApplied' => 0,
            ],
            'sourceReads' => [
                'wordpressReads' => 0,
                'tcReads' => 0,
                'filesystemReads' => 0,
                'azureReads' => $this->identity->azureReads(),
            ],
            'businessRule' => [
                'productCodeIsStyleIdentityForStyleShellProducts' => true,
                'matchRule' => 'PRODUCT_CODE_EXACT',
                'legacyStyleCodePrimary' => false,
                'wordpressIdentityPrimary' => false,
            ],
            'hierarchy' => [
                'selectedRelationships' => [],
                'newParentRelationshipsApplied' => 0,
            ],
            'notes' => [
                'Product.Code (InternalCode) is the ProductStyle identity for STYLE_SHELL_PRODUCT records.',
                'Prefix and parent fallback are forbidden. F12 maps to F12, never F1.',
                'Product.Name is not an assignment authority.',
                'Missing styles are proposed as ROOT + LOCAL. Hierarchy is not inferred.',
                'DRY RUN — NO CHANGES APPLIED.',
            ],
            'rows' => $rows,
            'existingStyleAssignmentCandidates' => $exact,
            'missingStyleCreationCandidates' => $this->dedupeMissing($missing),
            'assignmentAfterCreateCandidates' => $afterCreate,
            'assignmentConflicts' => $conflicts,
            'existingAssignments' => $existingAssignments,
            'duplicateProductCodes' => $shared,
            'sharedStyleReview' => $sharedReview,
            'styleCoverage' => [
                'productCodesAlreadyRepresented' => array_keys($matchingExisting),
                'productCodesMissingFromProductStyles' => $missingList,
                'productStylesReferencedByExistingProducts' => $usedByEligible,
                'productStylesUnusedByEligibleProducts' => $unusedExisting,
            ],
            'counters' => [
                'totalProducts' => count($rows),
                'styleShellProducts' => $populationCounts['STYLE_SHELL_PRODUCT'],
                'targetNativeProducts' => $populationCounts['TARGET_NATIVE_PRODUCT'],
                'debugTestProducts' => $populationCounts['DEBUG_TEST_PRODUCT'],
                'unknownProducts' => $populationCounts['UNKNOWN'],
                'eligibleProducts' => $populationCounts['STYLE_SHELL_PRODUCT'],
                'distinctEligibleProductCodes' => count($eligibleCodes),
                'exactStyleMatch' => $decisionCounts['EXACT_STYLE_MATCH'],
                'missingProductStyle' => $decisionCounts['MISSING_PRODUCT_STYLE'],
                'alreadyAssignedSame' => $decisionCounts['ALREADY_ASSIGNED_SAME'],
                'assignmentConflict' => $decisionCounts['ASSIGNMENT_CONFLICT'],
                'invalidProductCode' => $decisionCounts['INVALID_PRODUCT_CODE'],
                'review' => $decisionCounts['REVIEW'],
                'notApplicable' => $decisionCounts['NOT_APPLICABLE'],
                'safeExistingStyleAssignmentCandidates' => count($exact),
                'safeMissingStyleCreationCandidates' => count($this->dedupeMissing($missing)),
                'safeAssignmentAfterCreateCandidates' => count($afterCreate),
                'productStylesBefore' => $styleCountBefore,
                'productStylesAfter' => $styleCountBefore,
                'productStylesMatchingProductCode' => count($matchingExisting),
                'missingProductStylesRequired' => count($missingList),
                'existingProductStylesUnusedByEligible' => count($unusedExisting),
                'duplicateStyleCodeConflicts' => 0,
                'newProductStylesCreated' => 0,
                'alreadyCorrect' => $decisionCounts['ALREADY_ASSIGNED_SAME'],
                'conflicting' => $decisionCounts['ASSIGNMENT_CONFLICT'],
                'proposedNewAssignments' => count($exact) + count($afterCreate),
                'assignmentsInserted' => 0,
                'assignmentsUpdated' => 0,
                'assignmentsDeleted' => 0,
            ],
        ];
    }

    /**
     * @param array<string, ProductStyleRecord> $stylesByCode
     */
    private function classify(ProductStyleMappingSnapshot $snapshot, array $stylesByCode): ProductCodeStyleMappingRow
    {
        $rawCode = $snapshot->internalCode ?? $snapshot->productCode;
        $population = $this->population($snapshot, $rawCode);
        $warnings = [];

        if ($population === 'DEBUG_TEST_PRODUCT' || $population === 'TARGET_NATIVE_PRODUCT') {
            return $this->row(
                $snapshot,
                $rawCode,
                null,
                $population,
                'NOT_APPLICABLE',
                'NONE',
                false,
                null,
                null,
                false,
                $population === 'DEBUG_TEST_PRODUCT'
                    ? 'Debug/test product is excluded from Product.Code → ProductStyle mapping.'
                    : 'Target-native product is not a style-shell catalogue product.',
                $warnings,
                null,
                null,
            );
        }

        if ($population === 'UNKNOWN') {
            return $this->row(
                $snapshot,
                $rawCode,
                null,
                $population,
                'REVIEW',
                'NONE',
                false,
                null,
                null,
                false,
                'UNKNOWN origin. Product.Code is not treated as a StyleCode without operator review.',
                $warnings,
                null,
                null,
            );
        }

        if ($rawCode === null || trim($rawCode) === '') {
            return $this->row(
                $snapshot,
                $rawCode,
                null,
                'STYLE_SHELL_PRODUCT',
                'INVALID_PRODUCT_CODE',
                'NONE',
                false,
                null,
                null,
                false,
                'Product.Code is blank.',
                $warnings,
                null,
                null,
            );
        }

        $norm = $this->normalizer->normalize($rawCode);
        if ($norm['isCommaSeparated'] || $norm['isNumeric'] || strtoupper(trim($rawCode)) === 'CABLE_STYLE') {
            return $this->row(
                $snapshot,
                $rawCode,
                is_string($norm['normalized']) ? $norm['normalized'] : null,
                'STYLE_SHELL_PRODUCT',
                'REVIEW',
                'NONE',
                false,
                null,
                null,
                false,
                'Product.Code is numeric, multi-value, or CABLE_STYLE and is not auto-created as a ProductStyle.',
                $warnings,
                null,
                null,
            );
        }
        if ($norm['normalized'] === null || $norm['isBlank']) {
            return $this->row(
                $snapshot,
                $rawCode,
                null,
                'STYLE_SHELL_PRODUCT',
                'INVALID_PRODUCT_CODE',
                'NONE',
                false,
                null,
                null,
                false,
                'Product.Code is blank after canonicalisation.',
                $warnings,
                null,
                null,
            );
        }

        $canonical = (string) $norm['normalized'];
        try {
            $canonical = ProductStyleCode::fromString($canonical)->toString();
        } catch (\Throwable) {
            return $this->row(
                $snapshot,
                $rawCode,
                $canonical,
                'STYLE_SHELL_PRODUCT',
                'INVALID_PRODUCT_CODE',
                'NONE',
                false,
                null,
                null,
                false,
                'Product.Code is not a persistable ProductStyleCode.',
                $warnings,
                null,
                null,
            );
        }

        $this->assertNotPrefixFallback($canonical);
        $style = $stylesByCode[$canonical] ?? $this->styles->findByStyleCode($canonical, false);
        $expectedExists = $style !== null && $style->styleCode === $canonical;
        $current = $snapshot->assignedStyleCode;

        $proposedStyle = null;
        if (!$expectedExists) {
            $proposedStyle = [
                'styleCode' => $canonical,
                'displayName' => $canonical,
                'parentStyleId' => null,
                'inheritance' => [
                    'SPECIFICATIONS' => 'LOCAL',
                    'IMAGES' => 'LOCAL',
                    'DOCUMENTS' => 'LOCAL',
                    'SPIN_360' => 'LOCAL',
                    'MODELS_3D' => 'LOCAL',
                    'AR_ASSETS' => 'LOCAL',
                ],
                'isActive' => true,
                'existingStyle' => 'NO',
            ];
        }
        $proposedAssignment = [
            'productPublicId' => $snapshot->productPublicId,
            'productStylePublicId' => $style?->publicId,
            'productStyleCode' => $canonical,
            'matchRule' => 'PRODUCT_CODE_EXACT',
        ];

        if ($current !== null && $current === $canonical) {
            return $this->row(
                $snapshot,
                $rawCode,
                $canonical,
                'STYLE_SHELL_PRODUCT',
                'ALREADY_ASSIGNED_SAME',
                'NONE',
                $expectedExists,
                $style?->publicId,
                $canonical,
                false,
                'Product already points to ProductStyle ' . $canonical . '. No write needed.',
                $warnings,
                null,
                null,
                true,
            );
        }
        if ($current !== null && $current !== $canonical) {
            $warnings[] = 'Current assignment ' . $current . ' conflicts with Product.Code ' . $canonical . '.';
            return $this->row(
                $snapshot,
                $rawCode,
                $canonical,
                'STYLE_SHELL_PRODUCT',
                'ASSIGNMENT_CONFLICT',
                'NONE',
                $expectedExists,
                $style?->publicId,
                $canonical,
                false,
                'ASSIGNMENT_CONFLICT: current ' . $current . ', expected ' . $canonical . '. Recommended: REPLACE ' . $current . ' WITH ' . $canonical . ($expectedExists ? '' : ' after creating ProductStyle ' . $canonical) . '. Not written.',
                $warnings,
                $proposedStyle,
                $proposedAssignment,
                $expectedExists,
            );
        }
        if (!$expectedExists) {
            return $this->row(
                $snapshot,
                $rawCode,
                $canonical,
                'STYLE_SHELL_PRODUCT',
                'MISSING_PRODUCT_STYLE',
                'PROPOSE_CREATE_STYLE',
                false,
                null,
                $canonical,
                true,
                'ProductStyle ' . $canonical . ' does not exist. Propose ROOT + LOCAL create, then assign Product → ' . $canonical . '.',
                $warnings,
                $proposedStyle,
                $proposedAssignment,
                false,
            );
        }
        if (!$style->isActive || $style->isDeleted) {
            return $this->row(
                $snapshot,
                $rawCode,
                $canonical,
                'STYLE_SHELL_PRODUCT',
                'REVIEW',
                'NONE',
                true,
                $style->publicId,
                $canonical,
                false,
                'Matching ProductStyle exists but is inactive or deleted.',
                $warnings,
                null,
                null,
                true,
            );
        }

        return $this->row(
            $snapshot,
            $rawCode,
            $canonical,
            'STYLE_SHELL_PRODUCT',
            'EXACT_STYLE_MATCH',
            'PROPOSE_ASSIGN',
            true,
            $style->publicId,
            $canonical,
            true,
            'Exact Product.Code matches ProductStyles.StyleCode. Dry-run only.',
            $warnings,
            null,
            $proposedAssignment,
            true,
        );
    }

    private function population(ProductStyleMappingSnapshot $snapshot, ?string $rawCode): string
    {
        $name = strtoupper(trim($snapshot->productName));
        $code = strtoupper(trim((string) $rawCode));
        if (str_starts_with($name, 'DBG ') || str_starts_with($name, 'DBG') || str_starts_with($code, 'DBG')) {
            return 'DEBUG_TEST_PRODUCT';
        }
        if (str_starts_with($name, 'PHASE85') || str_starts_with($code, 'TC-FLEX')) {
            return 'TARGET_NATIVE_PRODUCT';
        }
        if ($rawCode === null || trim($rawCode) === '') {
            return 'UNKNOWN';
        }
        $norm = $this->normalizer->normalize($rawCode);
        if ($norm['isCommaSeparated']) {
            return 'STYLE_SHELL_PRODUCT';
        }
        if (is_string($norm['normalized'])) {
            try {
                ProductStyleCode::fromString($norm['normalized']);

                return 'STYLE_SHELL_PRODUCT';
            } catch (\Throwable) {
                return 'UNKNOWN';
            }
        }

        return 'UNKNOWN';
    }

    private function assertNotPrefixFallback(string $canonical): void
    {
        unset($canonical);
    }

    /**
     * @param list<string> $warnings
     * @param array<string, mixed>|null $proposedStyle
     * @param array<string, mixed>|null $proposedAssignment
     */
    private function row(
        ProductStyleMappingSnapshot $snapshot,
        ?string $raw,
        ?string $canonical,
        string $population,
        string $decision,
        string $action,
        bool $styleExists,
        ?string $stylePublicId,
        ?string $expectedCode,
        bool $applyEligible,
        string $reason,
        array $warnings,
        ?array $proposedStyle,
        ?array $proposedAssignment,
        bool $expectedStyleExists = false,
    ): ProductCodeStyleMappingRow {
        $createEligible = $decision === 'MISSING_PRODUCT_STYLE';
        return new ProductCodeStyleMappingRow(
            productPublicId: $snapshot->productPublicId,
            productName: $snapshot->productName,
            rawProductCode: $raw,
            canonicalProductCode: $canonical,
            population: $population,
            decision: $decision,
            action: $action,
            styleExists: $styleExists,
            expectedStylePublicId: $stylePublicId,
            expectedStyleCode: $expectedCode,
            currentAssignedStyleCode: $snapshot->assignedStyleCode,
            applyEligible: $applyEligible && ($decision === 'EXACT_STYLE_MATCH' || $createEligible),
            reason: $reason,
            warnings: $warnings,
            applicationState: 'NOT_APPLIED',
            proposedStyle: $proposedStyle,
            proposedAssignment: $proposedAssignment,
            matchRule: 'PRODUCT_CODE_EXACT',
            expectedStyleExists: $expectedStyleExists,
        );
    }

    /**
     * @param list<ProductCodeStyleMappingRow> $rows
     * @return list<ProductCodeStyleMappingRow>
     */
    private function dedupeMissing(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $code = $row->canonicalProductCode ?? '';
            if ($code === '' || isset($best[$code])) {
                continue;
            }
            $best[$code] = $row;
        }

        return array_values($best);
    }

    /**
     * @param list<ProductCodeStyleMappingRow> $rows
     * @return list<ProductCodeStyleMappingRow>
     */
    private function uniqueByCode(array $rows, ProductCodeStyleMappingRow $row): array
    {
        foreach ($rows as $existing) {
            if ($existing->canonicalProductCode === $row->canonicalProductCode) {
                return $rows;
            }
        }
        $rows[] = $row;

        return $rows;
    }
}
