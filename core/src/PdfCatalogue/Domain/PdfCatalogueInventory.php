<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue\Domain;

/**
 * Authoritative seed inventory from legacy template-catalogue-list.php.
 * PUR Marine is a single catalogue with AU_NZ + ASIA regions.
 */
final class PdfCatalogueInventory
{
    /**
     * @return list<array{
     *   seedKey: string,
     *   title: string,
     *   issue: string,
     *   regions: list<string>,
     *   legacyRelativePath: string,
     *   documentBlobPath: string,
     *   sortOrder: int
     * }>
     */
    public static function definitions(): array
    {
        return [
            self::def('aliflex-marine', 'ALIFLEX Marine', '13.0', ['AU_NZ'], '2026/05/ALIFLEX Marine.pdf', 10),
            self::def('dali-lighting-automation', 'DALI - Lighting Automation', '15.0', ['AU_NZ'], '2026/06/DALI-Lighting Automation.pdf', 20),
            self::def('drag-chain-festoon-elevator', 'Drag Chain, Festoon & Elevator', '15.0', ['AU_NZ'], '2026/06/DRAG CHAIN, FESTOON and ELEVATOR.pdf', 30),
            self::def('food-beverage', 'Food & Beverage', '1.0', ['AU_NZ'], '2026/05/Food & Beverage.pdf', 40),
            self::def('pur-marine', 'PUR Marine', '12.0', ['AU_NZ', 'ASIA'], '2026/05/PUR Marine.pdf', 50),
            self::def('ship-loaders-cranes', 'Ship Loaders & Cranes', '1.0', ['AU_NZ'], '2026/05/Ship Loaders & Cranes.pdf', 60),
            self::def('shore-power', 'Shore Power', '1.0', ['AU_NZ'], '2026/05/Shore Power.pdf', 70),
            self::def('temporary-power', 'Temporary Power', '1.0', ['AU_NZ'], '2026/05/Temporary Power.pdf', 80),
            self::def('water-treatment', 'Water Treatment', '1.0', ['AU_NZ'], '2026/05/Water Treatment.pdf', 90),
            self::def('marine-lszh', 'Marine LSZH', '14.0', ['USA'], '2026/06/TriCabUSA_LSZH_MARINE.pdf', 100),
        ];
    }

    /**
     * @param list<string> $regions
     * @return array{
     *   seedKey: string,
     *   title: string,
     *   issue: string,
     *   regions: list<string>,
     *   legacyRelativePath: string,
     *   documentBlobPath: string,
     *   sortOrder: int
     * }
     */
    private static function def(
        string $seedKey,
        string $title,
        string $issue,
        array $regions,
        string $legacyRelativePath,
        int $sortOrder,
    ): array {
        return [
            'seedKey' => $seedKey,
            'title' => $title,
            'issue' => $issue,
            'regions' => $regions,
            'legacyRelativePath' => $legacyRelativePath,
            'documentBlobPath' => 'catalogues/' . $seedKey . '/' . $issue . '/catalogue.pdf',
            'sortOrder' => $sortOrder,
        ];
    }
}
