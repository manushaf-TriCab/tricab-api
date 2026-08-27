<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

use TriCab\Core\Product\Domain\ValueObject\PriceListLegacyContext;

/**
 * Phase 13.2A approved dbco → PriceList → Currency mapping contract.
 *
 * Evidence: ProductConfigurator::getDebtCompany + 2026-08-19 WP pricing audit.
 * Does not write Azure data.
 */
final class DbcoPriceListMappingContract
{
    public const EXTERNAL_SYSTEM_CODE = PriceListLegacyContext::SYSTEM_CODE; // legacy-tc
    public const EXTERNAL_ENTITY_TYPE = PriceListLegacyContext::CONTEXT_TYPE; // dbco

    public const DECISION_APPROVED = 'APPROVED';
    public const DECISION_REVIEW = 'REVIEW';
    public const DECISION_BLOCKED = 'BLOCKED';
    public const DECISION_EXCLUDED = 'EXCLUDED';

    public const CLASSIFICATION_2519 = '2519_NOT_REQUIRED_FOR_PHASE_13_3';

    /**
     * @return list<array{
     *   dbco: int,
     *   priceListCode: string,
     *   name: string,
     *   currencyCode: string,
     *   regionCode: ?string,
     *   marketCode: ?string,
     *   confidence: string,
     *   decision: string,
     *   usage: string,
     *   phase133InScope: bool,
     *   notes: string
     * }>
     */
    public function all(): array
    {
        return [
            [
                'dbco' => 2507,
                'priceListCode' => 'TCA-BASE',
                'name' => 'Australia Base Cost',
                'currencyCode' => 'AUD',
                'regionCode' => 'AU',
                'marketCode' => null,
                'confidence' => 'HIGH',
                'decision' => self::DECISION_APPROVED,
                'usage' => 'CATALOGUE + STAFF (AU/NZ base-cost context)',
                'phase133InScope' => true,
                'notes' => 'ProductConfigurator::getDebtCompany: AU/NZ → 2507. NZ sell uses FX to NZD; no separate NZ PriceList.',
            ],
            [
                'dbco' => 2517,
                'priceListCode' => 'TCUS-BASE',
                'name' => 'United States Base Cost',
                'currencyCode' => 'USD',
                'regionCode' => 'AMER',
                'marketCode' => null,
                'confidence' => 'HIGH',
                'decision' => self::DECISION_APPROVED,
                'usage' => 'CATALOGUE + STAFF (US base-cost context)',
                'phase133InScope' => true,
                'notes' => 'ProductConfigurator::getDebtCompany: US → 2517. Phase 13.2 LOW confidence SUPERSEDED by source verification.',
            ],
            [
                'dbco' => 2519,
                'priceListCode' => 'TCASIA-BASE',
                'name' => 'Asia Base Cost',
                'currencyCode' => 'USD',
                'regionCode' => 'APAC',
                'marketCode' => null,
                'confidence' => 'LOW',
                'decision' => self::DECISION_EXCLUDED,
                'usage' => 'STAFF Price Lookup / RFQ region only — not getDebtCompany catalogue path',
                'phase133InScope' => false,
                'notes' => self::CLASSIFICATION_2519 . '; currency of stored base cost not proven for catalogue; exclude from Phase 13.3 dry-run scope.',
            ],
        ];
    }

    /**
     * @return array{
     *   dbco: int,
     *   priceListCode: string,
     *   name: string,
     *   currencyCode: string,
     *   regionCode: ?string,
     *   marketCode: ?string,
     *   confidence: string,
     *   decision: string,
     *   usage: string,
     *   phase133InScope: bool,
     *   notes: string
     * }|null
     */
    public function forDbco(int $dbco): ?array
    {
        foreach ($this->all() as $row) {
            if ($row['dbco'] === $dbco) {
                return $row;
            }
        }

        return null;
    }

    public function isApprovedForDryRun(int $dbco): bool
    {
        $row = $this->forDbco($dbco);

        return $row !== null
            && $row['phase133InScope'] === true
            && $row['decision'] === self::DECISION_APPROVED
            && $row['currencyCode'] !== '';
    }

    /**
     * @return list<int>
     */
    public function phase133ScopedDbcos(): array
    {
        $out = [];
        foreach ($this->all() as $row) {
            if ($row['phase133InScope'] && $row['decision'] === self::DECISION_APPROVED) {
                $out[] = $row['dbco'];
            }
        }

        return $out;
    }
}
