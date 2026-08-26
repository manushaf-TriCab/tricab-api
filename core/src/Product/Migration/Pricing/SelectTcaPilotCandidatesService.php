<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Filters Phase 13.3 dry-run resolutions into a TCA EXACT pilot set for one style.
 */
final class SelectTcaPilotCandidatesService
{
    /**
     * @param list<Phase133BaseCostDryRunResolution> $resolutions
     * @param array<string, array{variantCode: string, productPublicId?: string, variantPublicId?: string}> $variantMeta keyed by productVariantId
     * @return array{
     *   status: string,
     *   style: string,
     *   candidates: list<Phase133BaseCostDryRunResolution>,
     *   rejected: list<array{reason: string, resolution?: Phase133BaseCostDryRunResolution}>,
     *   preview: array<string, mixed>
     * }
     */
    public function select(
        array $resolutions,
        array $variantMeta,
        string $styleCode,
        int $maxVariants = 20,
    ): array {
        $style = strtoupper(trim($styleCode));
        if ($style === '') {
            return [
                'status' => Phase134PilotCliGuard::PILOT_STYLE_REQUIRED,
                'style' => '',
                'candidates' => [],
                'rejected' => [['reason' => Phase134PilotCliGuard::PILOT_STYLE_REQUIRED]],
                'preview' => [],
            ];
        }

        if ($maxVariants > Phase134PilotCliGuard::HARD_MAX) {
            return [
                'status' => Phase134PilotCliGuard::MAX_VARIANTS_EXCEEDED,
                'style' => $style,
                'candidates' => [],
                'rejected' => [['reason' => Phase134PilotCliGuard::MAX_VARIANTS_EXCEEDED]],
                'preview' => ['maxVariants' => $maxVariants, 'hardMax' => Phase134PilotCliGuard::HARD_MAX],
            ];
        }

        $candidates = [];
        $rejected = [];
        foreach ($resolutions as $r) {
            if ($r->dbco !== 2507 || $r->priceListCode !== 'TCA-BASE') {
                continue;
            }
            if ($r->readiness !== Phase133BaseCostDryRunResolution::READY) {
                continue;
            }
            if ($r->identityType !== PriceImportResolution::IDENTITY_EXACT) {
                $rejected[] = ['reason' => 'NOT_EXACT', 'resolution' => $r];
                continue;
            }
            if ($r->productVariantId === null) {
                $rejected[] = ['reason' => 'MISSING_VARIANT', 'resolution' => $r];
                continue;
            }
            $meta = $variantMeta[$r->productVariantId] ?? null;
            $code = strtoupper((string) ($meta['variantCode'] ?? $r->normalizedCode));
            if (!str_starts_with($code, $style . '-')) {
                continue;
            }
            if (preg_match('/^VC-|SAMPLE|DBG-/', $code) === 1) {
                $rejected[] = ['reason' => 'SYNTHETIC_OR_TEST', 'resolution' => $r];
                continue;
            }
            if (!is_numeric($r->baseCost) || bccomp($r->baseCost, '0', 6) <= 0) {
                $rejected[] = ['reason' => 'NON_POSITIVE_COST', 'resolution' => $r];
                continue;
            }
            $candidates[] = $r;
            if (count($candidates) >= $maxVariants) {
                break;
            }
        }

        usort(
            $candidates,
            static fn (Phase133BaseCostDryRunResolution $a, Phase133BaseCostDryRunResolution $b): int => strcmp($a->normalizedCode, $b->normalizedCode),
        );

        return [
            'status' => count($candidates) > 0 ? 'PILOT_CANDIDATES_READY' : 'PILOT_CANDIDATES_EMPTY',
            'style' => $style,
            'candidates' => $candidates,
            'rejected' => $rejected,
            'preview' => [
                'Pilot style' => $style,
                'Candidate variants' => count($candidates),
                'PriceList' => 'TCA-BASE',
                'Currency' => 'AUD',
                'dbco' => 2507,
                'Identity EXACT' => count($candidates),
                'Zero cost' => 0,
                'Ambiguous' => 0,
                'Collisions' => 0,
                'Expected INSERT' => count($candidates),
                'Expected NO_CHANGE' => 0,
                'Expected REVIEW' => 0,
            ],
        ];
    }
}
