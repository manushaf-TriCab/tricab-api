<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

/**
 * Pure effective snapshot merge logic (design reference — no persistence).
 *
 * Precedence: child/local override wins over parent inherited value for same key.
 */
final class EffectiveStyleSnapshotBuilder
{
    /**
     * @param list<array{styleCode: string, level: int, specs: array<string, string>}> $chain root-first
     * @param array<string, string> $localOverrides child style local specs
     * @return list<array<string, mixed>>
     */
    public function buildEffectiveSpecifications(array $chain, array $localOverrides): array
    {
        $merged = [];
        foreach ($chain as $node) {
            foreach ($node['specs'] as $definitionCode => $value) {
                if (!isset($merged[$definitionCode])) {
                    $merged[$definitionCode] = [
                        'definitionCode' => $definitionCode,
                        'effectiveValue' => $value,
                        'sourceStyleCode' => $node['styleCode'],
                        'sourceLevel' => $node['level'],
                        'inherited' => $node['level'] > 0,
                    ];
                }
            }
        }
        foreach ($localOverrides as $definitionCode => $value) {
            $merged[$definitionCode] = [
                'definitionCode' => $definitionCode,
                'effectiveValue' => $value,
                'sourceStyleCode' => $chain[array_key_last($chain)]['styleCode'] ?? '',
                'sourceLevel' => 0,
                'inherited' => false,
            ];
        }

        return array_values($merged);
    }

    /**
     * @param list<string> $descendantStyleCodes styles below changed node (excluding siblings)
     * @return list<string>
     */
    public function invalidationTargets(string $changedStyleCode, array $descendantStyleCodes): array
    {
        return array_values(array_unique(array_merge([$changedStyleCode], $descendantStyleCodes)));
    }
}
