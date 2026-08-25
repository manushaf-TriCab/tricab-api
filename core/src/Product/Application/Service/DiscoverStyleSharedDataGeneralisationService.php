<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Contract\StyleSharedDataEvidenceSource;

/**
 * Read-only dry-run: analyse Style families for parent shared-data generalisation.
 * ZERO business-data writes.
 */
final class DiscoverStyleSharedDataGeneralisationService
{
    public function __construct(
        private readonly StyleSharedDataEvidenceSource $evidence,
        private readonly StyleSharedDataClassifier $classifier,
    ) {
    }

    /**
     * @param array<string, mixed>|null $performanceBaseline
     * @return array<string, mixed>
     */
    public function dryRun(?array $performanceBaseline = null): array
    {
        $environment = $this->evidence->loadEnvironmentSafety();
        $familiesRaw = $this->evidence->listPersistedFamilies();
        $assignments = $this->evidence->listStyleAssignments();

        $childrenByParent = [];
        foreach ($familiesRaw as $row) {
            $parent = $row['parentStyleCode'];
            $childrenByParent[$parent][] = $row['childStyleCode'];
        }
        foreach ($childrenByParent as &$children) {
            $children = array_values(array_unique($children));
            sort($children);
        }
        unset($children);

        $productsByStyle = [];
        foreach ($assignments as $row) {
            $productsByStyle[$row['styleCode']][] = $row;
        }

        $allProductCodes = [];
        foreach ($assignments as $row) {
            $allProductCodes[] = $row['productCode'];
        }
        $allProductCodes = array_values(array_unique($allProductCodes));

        $specRows = $this->evidence->loadSpecificationsForProducts($allProductCodes);
        $mediaRows = $this->evidence->loadMediaForProducts($allProductCodes);
        $regionRows = $this->evidence->loadRegionsForProducts($allProductCodes);
        $resourceCounts = $this->evidence->loadResourceCountsForProducts($allProductCodes);

        $specsByProduct = $this->indexSpecs($specRows);
        $mediaByProduct = $this->indexMedia($mediaRows);
        $regionsByProduct = $this->indexRegions($regionRows);
        $countsByProduct = $this->indexResourceCounts($resourceCounts);

        $families = [];
        $exactSharedSpecTotal = 0;
        $seedSpecTotal = 0;
        $conflictSpecTotal = 0;
        $sharedMediaTotal = 0;
        $seedMediaTotal = 0;
        $sharedMarketTotal = 0;
        $incompleteMarketFamilies = 0;
        $proposedPackages = [];

        foreach ($childrenByParent as $parentCode => $childCodes) {
            $analysis = $this->analyseFamily(
                $parentCode,
                $childCodes,
                $productsByStyle,
                $specsByProduct,
                $mediaByProduct,
                $regionsByProduct,
                $countsByProduct,
            );
            $families[$parentCode] = $analysis;

            foreach ($analysis['specificationAnalysis'] as $spec) {
                match ($spec['decision']) {
                    StyleSharedDataClassifier::EXACT_SHARED => $exactSharedSpecTotal++,
                    StyleSharedDataClassifier::SEED_CANDIDATE => $seedSpecTotal++,
                    StyleSharedDataClassifier::CONFLICT => $conflictSpecTotal++,
                    default => null,
                };
            }
            foreach ($analysis['mediaAnalysis'] as $media) {
                if ($media['decision'] === StyleSharedDataClassifier::EXACT_SHARED_ASSET) {
                    $sharedMediaTotal++;
                }
                if ($media['decision'] === StyleSharedDataClassifier::SEED_CANDIDATE) {
                    $seedMediaTotal++;
                }
            }
            if (($analysis['marketAnalysis']['decision'] ?? '') === StyleSharedDataClassifier::MARKET_INTERSECTION) {
                $sharedMarketTotal += count($analysis['marketAnalysis']['intersection'] ?? []);
            }
            if (($analysis['marketAnalysis']['decision'] ?? '') === StyleSharedDataClassifier::MARKET_DATA_INCOMPLETE) {
                $incompleteMarketFamilies++;
            }
            $proposedPackages[] = $analysis['proposedApplyPackage'];
        }

        $bv = $families['BV'] ?? null;

        return [
            'environment' => $environment,
            'architectureAudit' => EffectiveStyleSnapshotDesign::architectureAudit(),
            'performanceBaseline' => $performanceBaseline ?? ['status' => 'NOT_MEASURED'],
            'families' => $families,
            'BV' => $bv,
            'RC' => $families['RC'] ?? null,
            'DV' => $families['DV'] ?? null,
            'RR' => $families['RR'] ?? null,
            'BH' => $families['BH'] ?? null,
            'specificationAnalysis' => $this->aggregateByDecision($families, 'specificationAnalysis'),
            'mediaAnalysis' => $this->aggregateByDecision($families, 'mediaAnalysis'),
            'marketAnalysis' => array_map(static fn (array $f): array => $f['marketAnalysis'], $families),
            'documentAnalysis' => array_map(static fn (array $f): array => $f['documentAnalysis'], $families),
            'spin360Analysis' => array_map(static fn (array $f): array => $f['spin360Analysis'], $families),
            'model3dAnalysis' => array_map(static fn (array $f): array => $f['model3dAnalysis'], $families),
            'arAnalysis' => array_map(static fn (array $f): array => $f['arAnalysis'], $families),
            'seedCandidates' => $this->collectSeedCandidates($families),
            'conflicts' => $this->collectConflicts($families),
            'effectiveSnapshotDesign' => EffectiveStyleSnapshotDesign::design(),
            'invalidationPlan' => EffectiveStyleSnapshotDesign::invalidationPlan(),
            'proposedApplyPackage' => $proposedPackages,
            'writeGuard' => EffectiveStyleSnapshotDesign::writeGuard(),
            'summary' => [
                'familyCount' => count($families),
                'exactSharedSpecCandidates' => $exactSharedSpecTotal,
                'seedSpecCandidates' => $seedSpecTotal,
                'conflictingSpecCandidates' => $conflictSpecTotal,
                'sharedMediaCandidates' => $sharedMediaTotal,
                'seedMediaCandidates' => $seedMediaTotal,
                'sharedMarketCandidates' => $sharedMarketTotal,
                'incompleteMarketFamilies' => $incompleteMarketFamilies,
            ],
        ];
    }

    /**
     * @param list<string> $childCodes
     * @param array<string, list<array<string, mixed>>> $productsByStyle
     * @param array<string, list<array<string, mixed>>> $specsByProduct
     * @param array<string, list<array<string, mixed>>> $mediaByProduct
     * @param array<string, list<string>> $regionsByProduct
     * @param array<string, array<string, int>> $countsByProduct
     * @return array<string, mixed>
     */
    private function analyseFamily(
        string $parentCode,
        array $childCodes,
        array $productsByStyle,
        array $specsByProduct,
        array $mediaByProduct,
        array $regionsByProduct,
        array $countsByProduct,
    ): array {
        $childProducts = [];
        $childMeta = [];
        foreach ($childCodes as $childCode) {
            $assigned = $productsByStyle[$childCode] ?? [];
            $primary = $assigned[0] ?? null;
            if ($primary !== null) {
                $childProducts[$childCode] = $primary;
                $pc = $primary['productCode'];
                $childMeta[$childCode] = [
                    'specCount' => count($specsByProduct[$pc] ?? []),
                    'productCode' => $pc,
                ];
            }
        }

        $seedStyle = $this->classifier->inferSeedChild(array_keys($childProducts), $childMeta);
        $seedProduct = $seedStyle !== null ? ($childProducts[$seedStyle]['productCode'] ?? null) : null;

        $definitionCodes = [];
        foreach ($childProducts as $childCode => $product) {
            foreach ($specsByProduct[$product['productCode']] ?? [] as $spec) {
                $definitionCodes[$spec['definitionCode']] = $spec['definitionName'];
            }
        }

        $specAnalysis = [];
        foreach ($definitionCodes as $defCode => $defName) {
            $valuesByChild = [];
            foreach ($childProducts as $childCode => $product) {
                $valuesByChild[$childCode] = $this->specValueForProduct(
                    $specsByProduct[$product['productCode']] ?? [],
                    $defCode,
                );
            }
            $classified = $this->classifier->classifySpecification($defCode, $valuesByChild);
            $classified['definitionName'] = $defName;
            $specAnalysis[] = $classified;
        }

        $mediaByChild = [];
        foreach ($childProducts as $childCode => $product) {
            $mediaByChild[$childCode] = array_map(static fn (array $m): array => [
                'storagePath' => $m['storagePath'],
                'roleCode' => $m['roleCode'],
            ], $mediaByProduct[$product['productCode']] ?? []);
        }
        $mediaAnalysis = $this->classifier->classifyMediaAssets($mediaByChild);

        $regionsByChild = [];
        foreach ($childProducts as $childCode => $product) {
            $regionsByChild[$childCode] = $regionsByProduct[$product['productCode']] ?? [];
        }
        $marketAnalysis = $this->classifier->classifyMarkets($regionsByChild);

        $productSummaries = [];
        foreach ($childProducts as $childCode => $product) {
            $pc = $product['productCode'];
            $productSummaries[$childCode] = [
                'productCode' => $pc,
                'productPublicId' => $product['productPublicId'],
                'productName' => $product['productName'],
                'statusCode' => $product['statusCode'],
                'isActive' => $product['isActive'],
                'specCount' => count($specsByProduct[$pc] ?? []),
                'imageCount' => count($mediaByProduct[$pc] ?? []),
                'documentCount' => $countsByProduct[$pc]['documents'] ?? 0,
                'spin360Count' => $countsByProduct[$pc]['spin360'] ?? 0,
                'models3dCount' => $countsByProduct[$pc]['models3d'] ?? 0,
                'arCount' => $countsByProduct[$pc]['ar'] ?? 0,
                'regions' => $regionsByProduct[$pc] ?? [],
            ];
        }

        $parentProducts = $productsByStyle[$parentCode] ?? [];

        return [
            'parentStyle' => $parentCode,
            'children' => $childCodes,
            'parentDirectProducts' => array_map(static fn (array $p): string => $p['productCode'], $parentProducts),
            'childProducts' => $productSummaries,
            'seedCandidate' => [
                'seedStyle' => $seedStyle,
                'seedProduct' => $seedProduct,
                'reason' => $seedStyle !== null
                    ? 'Child with highest populated specification count (tie-break alphabetical).'
                    : 'No assigned child products.',
                'confidence' => $seedStyle !== null ? 'LOW_UNTIL_SIBLING_EVIDENCE' : 'NONE',
                'siblingCoverage' => sprintf('%d/%d children assigned', count($childProducts), count($childCodes)),
            ],
            'specificationAnalysis' => $specAnalysis,
            'mediaAnalysis' => $mediaAnalysis,
            'marketAnalysis' => $marketAnalysis,
            'documentAnalysis' => $this->resourceAnalysis($childProducts, $countsByProduct, 'documents'),
            'spin360Analysis' => $this->resourceAnalysis($childProducts, $countsByProduct, 'spin360'),
            'model3dAnalysis' => $this->resourceAnalysis($childProducts, $countsByProduct, 'models3d'),
            'arAnalysis' => $this->resourceAnalysis($childProducts, $countsByProduct, 'ar'),
            'recommendedInheritance' => [
                'specifications' => 'INHERIT_AND_EXTEND',
                'images' => 'INHERIT_AND_EXTEND',
                'documents' => 'INHERIT_AND_EXTEND',
                'markets' => 'INHERIT_AND_EXTEND',
                'spin360' => 'INHERIT_AND_EXTEND',
                'models3d' => 'INHERIT_AND_EXTEND',
                'ar' => 'INHERIT_AND_EXTEND',
            ],
            'proposedApplyPackage' => $this->buildApplyPackage(
                $parentCode,
                $specAnalysis,
                $mediaAnalysis,
                $marketAnalysis,
                $seedStyle,
                $seedProduct,
            ),
            'canSeedSpecifications' => $this->canSafelySeed($specAnalysis),
            'canSeedImages' => $this->canSafelySeedMedia($mediaAnalysis),
            'canSeedMarkets' => $this->canSafelySeedMarkets($marketAnalysis),
        ];
    }

    /**
     * @param list<array<string, mixed>> $specs
     */
    private function specValueForProduct(array $specs, string $definitionCode): ?string
    {
        foreach ($specs as $spec) {
            if (($spec['definitionCode'] ?? '') === $definitionCode) {
                return $spec['displayValue'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param list<array{productCode: string, definitionCode: string, definitionName: string, displayValue: string}> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexSpecs(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['productCode']][] = $row;
        }

        return $out;
    }

    /**
     * @param list<array{productCode: string, storagePath: string, roleCode: ?string, mediaTypeCode: string}> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexMedia(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['productCode']][] = $row;
        }

        return $out;
    }

    /**
     * @param list<array{productCode: string, regionCode: string}> $rows
     * @return array<string, list<string>>
     */
    private function indexRegions(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['productCode']][] = $row['regionCode'];
        }

        return $out;
    }

    /**
     * @param list<array{productCode: string, resourceType: string, count: int}> $rows
     * @return array<string, array<string, int>>
     */
    private function indexResourceCounts(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['productCode']][$row['resourceType']] = $row['count'];
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $childProducts
     * @param array<string, array<string, int>> $countsByProduct
     * @return array<string, mixed>
     */
    private function resourceAnalysis(array $childProducts, array $countsByProduct, string $type): array
    {
        $byChild = [];
        foreach ($childProducts as $childCode => $product) {
            $byChild[$childCode] = $countsByProduct[$product['productCode']][$type] ?? 0;
        }
        $nonZero = array_filter($byChild, static fn (int $c): bool => $c > 0);
        if ($nonZero === []) {
            return ['decision' => StyleSharedDataClassifier::INSUFFICIENT_EVIDENCE, 'countsByChild' => $byChild];
        }
        if (count($nonZero) === 1) {
            return [
                'decision' => StyleSharedDataClassifier::SEED_CANDIDATE,
                'countsByChild' => $byChild,
                'seedStyle' => array_key_first($nonZero),
            ];
        }

        return ['decision' => StyleSharedDataClassifier::REVIEW, 'countsByChild' => $byChild];
    }

    /**
     * @param list<array<string, mixed>> $specAnalysis
     * @param list<array<string, mixed>> $mediaAnalysis
     * @return array<string, mixed>
     */
    private function buildApplyPackage(
        string $parentCode,
        array $specAnalysis,
        array $mediaAnalysis,
        array $marketAnalysis,
        ?string $seedStyle,
        ?string $seedProduct,
    ): array {
        return [
            'parentStyle' => $parentCode,
            'specifications' => array_map(static fn (array $s): array => [
                'definition' => $s['definitionCode'] ?? '',
                'definitionName' => $s['definitionName'] ?? '',
                'sourceProduct' => $seedProduct,
                'sourceStyle' => $seedStyle,
                'decision' => $s['decision'] ?? '',
                'approved' => false,
            ], $specAnalysis),
            'media' => array_map(static fn (array $m): array => [
                'storagePath' => $m['storagePath'] ?? '',
                'decision' => $m['decision'] ?? '',
                'approved' => false,
            ], $mediaAnalysis),
            'markets' => [
                'decision' => $marketAnalysis['decision'] ?? '',
                'intersection' => $marketAnalysis['intersection'] ?? [],
                'approved' => false,
            ],
            'recommendedInheritance' => [
                'specifications' => 'INHERIT_AND_EXTEND',
                'images' => 'INHERIT_AND_EXTEND',
                'markets' => 'INHERIT_AND_EXTEND',
            ],
        ];
    }

    /** @return 'YES'|'NO'|'PARTIAL' */
    private function canSafelySeed(array $specAnalysis): string
    {
        if ($specAnalysis === []) {
            return 'NO';
        }
        $exact = 0;
        $seed = 0;
        foreach ($specAnalysis as $spec) {
            if (($spec['decision'] ?? '') === StyleSharedDataClassifier::EXACT_SHARED) {
                $exact++;
            }
            if (($spec['decision'] ?? '') === StyleSharedDataClassifier::SEED_CANDIDATE) {
                $seed++;
            }
        }
        if ($exact > 0 && $seed === 0) {
            return 'YES';
        }
        if ($exact > 0 || $seed > 0) {
            return 'PARTIAL';
        }

        return 'NO';
    }

    /** @return 'YES'|'NO'|'PARTIAL' */
    private function canSafelySeedMedia(array $mediaAnalysis): string
    {
        $shared = 0;
        $seed = 0;
        foreach ($mediaAnalysis as $media) {
            if (($media['decision'] ?? '') === StyleSharedDataClassifier::EXACT_SHARED_ASSET) {
                $shared++;
            }
            if (($media['decision'] ?? '') === StyleSharedDataClassifier::SEED_CANDIDATE) {
                $seed++;
            }
        }
        if ($shared > 0 && $seed === 0) {
            return 'PARTIAL';
        }
        if ($shared > 0 || $seed > 0) {
            return 'PARTIAL';
        }

        return 'NO';
    }

    /** @return 'YES'|'NO'|'PARTIAL' */
    private function canSafelySeedMarkets(array $marketAnalysis): string
    {
        $decision = $marketAnalysis['decision'] ?? '';
        if ($decision === StyleSharedDataClassifier::MARKET_INTERSECTION && ($marketAnalysis['intersection'] ?? []) !== []) {
            return 'PARTIAL';
        }
        if ($decision === StyleSharedDataClassifier::MARKET_SEED_CANDIDATE) {
            return 'PARTIAL';
        }

        return 'NO';
    }

    /**
     * @param array<string, array<string, mixed>> $families
     * @return array<string, list<array<string, mixed>>>
     */
    private function aggregateByDecision(array $families, string $key): array
    {
        $out = [];
        foreach ($families as $parent => $family) {
            foreach ($family[$key] ?? [] as $item) {
                $decision = $item['decision'] ?? 'UNKNOWN';
                $item['parentStyle'] = $parent;
                $out[$decision][] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $families
     * @return list<array<string, mixed>>
     */
    private function collectSeedCandidates(array $families): array
    {
        $out = [];
        foreach ($families as $parent => $family) {
            $seed = $family['seedCandidate'] ?? null;
            if ($seed !== null && ($seed['seedStyle'] ?? null) !== null) {
                $out[] = array_merge(['parentStyle' => $parent], $seed);
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $families
     * @return list<array<string, mixed>>
     */
    private function collectConflicts(array $families): array
    {
        $out = [];
        foreach ($families as $parent => $family) {
            foreach ($family['specificationAnalysis'] ?? [] as $spec) {
                if (($spec['decision'] ?? '') === StyleSharedDataClassifier::CONFLICT) {
                    $out[] = array_merge(['parentStyle' => $parent], $spec);
                }
            }
        }

        return $out;
    }
}
