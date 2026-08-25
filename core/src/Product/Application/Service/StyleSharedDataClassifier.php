<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

/**
 * Pure classification rules for parent Style shared-data generalisation (dry-run).
 */
final class StyleSharedDataClassifier
{
    public const EXACT_SHARED = 'EXACT_SHARED';
    public const MAJORITY_SHARED = 'MAJORITY_SHARED';
    public const CHILD_SPECIFIC = 'CHILD_SPECIFIC';
    public const CONFLICT = 'CONFLICT';
    public const MISSING_SIBLING_DATA = 'MISSING_SIBLING_DATA';
    public const INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';
    public const SEED_CANDIDATE = 'SEED_CANDIDATE';
    public const REVIEW = 'REVIEW';

    public const EXACT_SHARED_ASSET = 'EXACT_SHARED_ASSET';
    public const COMMON_ASSET = 'COMMON_ASSET';
    public const CHILD_SPECIFIC_ASSET = 'CHILD_SPECIFIC_ASSET';
    public const ROLE_CONFLICT = 'ROLE_CONFLICT';

    public const MARKET_INTERSECTION = 'MARKET_INTERSECTION';
    public const MARKET_DATA_INCOMPLETE = 'MARKET_DATA_INCOMPLETE';
    public const MARKET_SEED_CANDIDATE = 'MARKET_SEED_CANDIDATE';

    /**
     * @param array<string, ?string> $valuesByChild keyed by child style/product code
     * @return array<string, mixed>
     */
    public function classifySpecification(string $definitionCode, array $valuesByChild): array
    {
        $populated = [];
        foreach ($valuesByChild as $child => $value) {
            if ($value !== null && trim($value) !== '') {
                $populated[$child] = $this->normalize($value);
            }
        }
        $total = count($valuesByChild);
        $populatedCount = count($populated);

        if ($populatedCount === 0) {
            return [
                'definitionCode' => $definitionCode,
                'decision' => self::INSUFFICIENT_EVIDENCE,
                'valuesByChild' => $valuesByChild,
                'populatedCount' => 0,
                'approved' => false,
            ];
        }

        if ($populatedCount === 1) {
            $onlyChild = array_key_first($populated);

            return [
                'definitionCode' => $definitionCode,
                'decision' => self::SEED_CANDIDATE,
                'seedStyle' => $onlyChild,
                'valuesByChild' => $valuesByChild,
                'populatedCount' => 1,
                'siblingCoverage' => sprintf('1/%d', $total),
                'approved' => false,
                'note' => 'Single populated sibling — seed candidate only, not EXACT_SHARED.',
            ];
        }

        $uniqueValues = array_values(array_unique($populated));
        if (count($uniqueValues) === 1 && $populatedCount === $total) {
            return [
                'definitionCode' => $definitionCode,
                'decision' => self::EXACT_SHARED,
                'sharedValue' => $uniqueValues[0],
                'valuesByChild' => $valuesByChild,
                'populatedCount' => $populatedCount,
                'approved' => false,
            ];
        }

        if ($populatedCount < $total) {
            if (count($uniqueValues) === 1) {
                return [
                    'definitionCode' => $definitionCode,
                    'decision' => self::SEED_CANDIDATE,
                    'sharedValueAmongPopulated' => $uniqueValues[0],
                    'valuesByChild' => $valuesByChild,
                    'populatedCount' => $populatedCount,
                    'siblingCoverage' => sprintf('%d/%d', $populatedCount, $total),
                    'approved' => false,
                    'note' => 'Missing sibling data — cannot claim parent EXACT_SHARED.',
                ];
            }

            return [
                'definitionCode' => $definitionCode,
                'decision' => self::MISSING_SIBLING_DATA,
                'valuesByChild' => $valuesByChild,
                'populatedCount' => $populatedCount,
                'approved' => false,
            ];
        }

        $counts = array_count_values($populated);
        arsort($counts);
        $topValue = array_key_first($counts);
        $topCount = $counts[$topValue];
        if ($topCount > 1 && $topCount >= (int) ceil($total / 2) && count($uniqueValues) > 1) {
            return [
                'definitionCode' => $definitionCode,
                'decision' => self::MAJORITY_SHARED,
                'majorityValue' => $topValue,
                'majorityCount' => $topCount,
                'valuesByChild' => $valuesByChild,
                'approved' => false,
            ];
        }

        if (count($uniqueValues) === $total) {
            return [
                'definitionCode' => $definitionCode,
                'decision' => self::CHILD_SPECIFIC,
                'valuesByChild' => $valuesByChild,
                'approved' => false,
            ];
        }

        return [
            'definitionCode' => $definitionCode,
            'decision' => self::CONFLICT,
            'valuesByChild' => $valuesByChild,
            'approved' => false,
        ];
    }

    /**
     * @param array<string, list<array{storagePath: string, roleCode: ?string}>> $assetsByChild
     * @return list<array<string, mixed>>
     */
    public function classifyMediaAssets(array $assetsByChild): array
    {
        $pathToChildren = [];
        $rolePaths = [];
        foreach ($assetsByChild as $child => $assets) {
            foreach ($assets as $asset) {
                $path = trim($asset['storagePath'] ?? '');
                if ($path === '') {
                    continue;
                }
                $pathToChildren[$path][] = $child;
                $role = strtolower(trim((string) ($asset['roleCode'] ?? '')));
                if (in_array($role, ['primary', 'hero', 'thumbnail'], true)) {
                    $rolePaths[$path] = true;
                }
            }
        }
        $totalChildren = count($assetsByChild);
        $out = [];
        foreach ($pathToChildren as $path => $children) {
            $children = array_values(array_unique($children));
            $count = count($children);
            $isHeroRole = isset($rolePaths[$path]);
            if ($count === $totalChildren && $totalChildren > 1) {
                $decision = self::EXACT_SHARED_ASSET;
            } elseif ($count > 1) {
                $decision = self::COMMON_ASSET;
            } else {
                $decision = self::CHILD_SPECIFIC_ASSET;
            }
            if ($isHeroRole && $count < $totalChildren) {
                $decision = self::SEED_CANDIDATE;
            }
            $out[] = [
                'storagePath' => $path,
                'decision' => $decision,
                'children' => $children,
                'isHeroRole' => $isHeroRole,
                'approved' => false,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $regionsByChild region codes per child
     * @return array<string, mixed>
     */
    public function classifyMarkets(array $regionsByChild): array
    {
        $total = count($regionsByChild);
        $populated = array_filter($regionsByChild, static fn (array $codes): bool => $codes !== []);
        $populatedCount = count($populated);

        if ($populatedCount === 0) {
            return [
                'decision' => self::INSUFFICIENT_EVIDENCE,
                'intersection' => [],
                'approved' => false,
            ];
        }

        if ($populatedCount === 1 && $total === 1) {
            $only = array_key_first($populated);

            return [
                'decision' => self::MARKET_SEED_CANDIDATE,
                'seedStyle' => $only,
                'seedRegions' => array_values($populated[$only]),
                'siblingCoverage' => '1/1',
                'approved' => false,
            ];
        }

        if ($populatedCount < $total) {
            $only = array_key_first($populated);

            return [
                'decision' => self::MARKET_DATA_INCOMPLETE,
                'populatedCount' => $populatedCount,
                'totalChildren' => $total,
                'seedStyle' => $only,
                'seedRegions' => $only !== false ? array_values($populated[$only]) : [],
                'approved' => false,
                'note' => 'Incomplete sibling market data — seed proposal only.',
            ];
        }

        $sets = array_map(static fn (array $codes): array => array_values(array_unique(array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            $codes,
        ))), $populated);
        $intersection = array_shift($sets);
        foreach ($sets as $set) {
            $intersection = array_values(array_intersect($intersection ?? [], $set));
        }

        return [
            'decision' => self::MARKET_INTERSECTION,
            'intersection' => $intersection ?? [],
            'regionsByChild' => $regionsByChild,
            'approved' => false,
            'note' => 'Intersection only — not union. Requires operator approval.',
        ];
    }

    /**
     * Pick likely seed child: first assigned child with most populated specs, else first alphabetically.
     *
     * @param list<string> $childStyleCodes
     * @param array<string, array{specCount: int, productCode: string}> $childMeta
     */
    public function inferSeedChild(array $childStyleCodes, array $childMeta): ?string
    {
        if ($childStyleCodes === []) {
            return null;
        }
        usort($childStyleCodes, static function (string $a, string $b) use ($childMeta): int {
            $ca = $childMeta[$a]['specCount'] ?? 0;
            $cb = $childMeta[$b]['specCount'] ?? 0;
            if ($ca !== $cb) {
                return $cb <=> $ca;
            }

            return $a <=> $b;
        });

        return $childStyleCodes[0] ?? null;
    }

    private function normalize(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    }
}
