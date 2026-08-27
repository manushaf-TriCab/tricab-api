<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Proven cyrte identity mappings for catalogue FX (ProductConfigurator constants + get_rate).
 * Full FX matrix remains FX_MAPPING_REVIEW for unresolved ids.
 */
final class LegacyCyrteMappingContract
{
    public const AUD = 3200;
    public const USD = 3201;
    public const NZD = 1006807;

    /**
     * @return array<int, array{currencyCode: string, confidence: string, status: string}>
     */
    public function provenMappings(): array
    {
        return [
            self::AUD => [
                'currencyCode' => 'AUD',
                'confidence' => 'HIGH',
                'status' => 'CONFIRMED',
            ],
            self::USD => [
                'currencyCode' => 'USD',
                'confidence' => 'HIGH',
                'status' => 'CONFIRMED',
            ],
            self::NZD => [
                'currencyCode' => 'NZD',
                'confidence' => 'HIGH',
                'status' => 'CONFIRMED',
            ],
        ];
    }

    public function currencyForId(int $cyrteId): ?string
    {
        $row = $this->provenMappings()[$cyrteId] ?? null;

        return $row['currencyCode'] ?? null;
    }

    public function fxRequiredForBaseCostMigration(): bool
    {
        return false;
    }
}
