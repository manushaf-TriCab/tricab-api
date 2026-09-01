<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\DTO;

final readonly class CableCalculationBand
{
    public function __construct(
        public string $temperatureBand,
        public string $style,
        public float $activeSizeMm2,
        public ?float $earthSizeMm2,
        public ?float $earthScreenSizeMm2,
        public int $runs,
        public float $currentRatingA,
        public int $operatingTemperatureC,
        public float $voltageDropPercent,
        public ?float $faultLoop,
        public ?float $faultRatingActive,
        public ?float $faultRatingEarth,
        public ?string $spaceRequirement,
        public ?float $weightKg,
        public ?float $quantityM,
        public ?int $multiple,
        public string $productCodeActive,
        public ?string $productCodeEarth,
        public ?int $nc,
        public ?int $ne,
        public ?int $earthNe,
    ) {
    }

    /** @param array<string, mixed> $legacy */
    public static function fromLegacyBand(string $bandLabel, array $legacy): self
    {
        return new self(
            temperatureBand: $bandLabel,
            style: (string) ($legacy['style'] ?? ''),
            activeSizeMm2: (float) ($legacy['xa'] ?? 0),
            earthSizeMm2: isset($legacy['earth_size']) ? (float) $legacy['earth_size'] : null,
            earthScreenSizeMm2: isset($legacy['xae']) ? (float) $legacy['xae'] : null,
            runs: (int) ($legacy['runs'] ?? 1),
            currentRatingA: (float) ($legacy['cr'] ?? 0),
            operatingTemperatureC: (int) ($legacy['ot'] ?? 0),
            voltageDropPercent: (float) ($legacy['vd'] ?? 0),
            faultLoop: isset($legacy['fault-loop']) ? (float) $legacy['fault-loop'] : null,
            faultRatingActive: isset($legacy['fr-active']) ? (float) $legacy['fr-active'] : null,
            faultRatingEarth: isset($legacy['fr-earth']) ? (float) $legacy['fr-earth'] : null,
            spaceRequirement: isset($legacy['space']) ? (string) $legacy['space'] : null,
            weightKg: isset($legacy['weight']) ? (float) $legacy['weight'] : null,
            quantityM: isset($legacy['qty']) ? (float) $legacy['qty'] : null,
            multiple: isset($legacy['multiple']) ? (int) $legacy['multiple'] : null,
            productCodeActive: (string) ($legacy['productCodeActive'] ?? ''),
            productCodeEarth: isset($legacy['productCodeEarth']) ? (string) $legacy['productCodeEarth'] : null,
            nc: isset($legacy['nc']) ? (int) $legacy['nc'] : null,
            ne: isset($legacy['ne']) ? (int) $legacy['ne'] : null,
            earthNe: isset($legacy['earth_ne']) ? (int) $legacy['earth_ne'] : null,
        );
    }
}
