<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\Presentation;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationBand;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationResult;

/**
 * Legacy HTML presentation layer (ui.php / include-cable-calc.php).
 * Does NOT alter engineering raw values used for parity.
 *
 * Proven display transforms:
 * - ot: round()
 * - weight: round(..., 1)
 * - space: strip trailing ".0"
 * - cr: printed raw (no nearest-10 / no rounding) — "49A" not "50A"
 * - vd / fault / loop: printed raw
 */
final class CableCalculationPresenter
{
    /**
     * @return array{
     *   bands: list<array<string, mixed>>,
     *   primaryBand: array<string, mixed>|null,
     *   voltageDropVolts: float|null
     * }
     */
    public static function present(CableCalculationResult $result): array
    {
        $bands = array_map(
            fn (CableCalculationBand $band): array => self::presentBand($band, $result->request),
            $result->bands,
        );

        $primary = $bands[0] ?? null;

        return [
            'bands' => $bands,
            'primaryBand' => $primary,
            'voltageDropVolts' => $primary['voltageDropVolts'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public static function presentBand(CableCalculationBand $band, CableCalculationRequest $request): array
    {
        $vdVolts = $request->voltage > 0
            ? round($band->voltageDropPercent * $request->voltage / 100, 2)
            : null;

        return [
            'temperatureBand' => $band->temperatureBand,
            'style' => $band->style,
            'activeSizeMm2' => $band->activeSizeMm2,
            'earthSizeMm2' => $band->earthSizeMm2,
            'earthScreenSizeMm2' => $band->earthScreenSizeMm2,
            'runs' => $band->runs,
            'currentRatingA' => $band->currentRatingA,
            'currentRatingDisplay' => self::formatAmps($band->currentRatingA),
            'operatingTemperatureC' => $band->operatingTemperatureC,
            'operatingTemperatureDisplayC' => (int) round($band->operatingTemperatureC),
            'voltageDropPercent' => $band->voltageDropPercent,
            'voltageDropPercentDisplay' => self::formatVd($band->voltageDropPercent),
            'voltageDropVolts' => $vdVolts,
            'faultLoop' => $band->faultLoop,
            'faultRatingActive' => $band->faultRatingActive,
            'faultRatingEarth' => $band->faultRatingEarth,
            'spaceRequirement' => $band->spaceRequirement,
            'spaceRequirementDisplay' => self::formatSpace($band->spaceRequirement),
            'weightKg' => $band->weightKg,
            'weightDisplayKg' => $band->weightKg === null ? null : round($band->weightKg, 1),
            'quantityM' => $band->quantityM,
            'multiple' => $band->multiple,
            'productCodeActive' => $band->productCodeActive,
            'productCodeEarth' => $band->productCodeEarth,
            'nc' => $band->nc,
            'ne' => $band->ne,
            'earthNe' => $band->earthNe,
        ];
    }

    private static function formatAmps(float $amps): string
    {
        // Legacy: $array_90['cr'] . "A" — no rounding.
        if (abs($amps - round($amps)) < 0.0001) {
            return ((int) round($amps)) . 'A';
        }

        return rtrim(rtrim(sprintf('%.2f', $amps), '0'), '.') . 'A';
    }

    private static function formatVd(float $vd): string
    {
        return rtrim(rtrim(sprintf('%.2f', $vd), '0'), '.') . '%';
    }

    private static function formatSpace(?string $space): ?string
    {
        if ($space === null || $space === '') {
            return $space;
        }

        return str_replace('.0', '', $space);
    }
}
