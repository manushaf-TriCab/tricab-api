<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

/**
 * Port of getMaskCodeForCatCodeForLookup from template-price-lookup.php.
 * Colour-code mask fallback when prefix search returns empty.
 */
final class PriceLookupColourMask
{
    /** @var list<string> */
    private const COLOR_CODES = [
        'BL', 'GR', 'GY', 'RE', 'WH', 'YE', 'OR', 'BK', 'MA', 'G1', 'BR', 'LTBL', 'EA', 'DKBL', 'NO', 'VI', 'PI',
        'RB', 'B1', 'B2', 'B3', 'L1', 'BLPI', 'L2', 'N2', 'N1', 'CL', 'DB', 'DBPI', 'D2', 'D1', 'LB', 'LG', 'O2',
        'P1', 'O1', 'P3', 'PIDB', 'PU', 'M1', 'M2', 'REBK', 'REDB', 'REPU', 'R1', 'R2', 'Y1', 'Y2', 'L3', 'YEBL',
        'LBWH', 'B4', 'Y3', 'YR', 'ORGY', 'BG', 'BRBL', 'REPI', 'MG', 'NATURAL', 'L4', 'R4', 'R3', 'L5', 'W1',
        'W4', 'FG', 'BKRE', 'BRBK', 'BLBK', 'ORBK', 'YEBK', 'WHBK', 'GRBK', 'BKWH', 'BLRE', 'WHRE', 'WHPU',
        'WHBL', 'WHBR', 'WHYE', 'BRRE', 'BRPU', 'BRYE', 'PUWH', 'BKGR', 'BKOR', 'BKBL', 'WHGR', 'DG', 'BLWH',
        'IV', 'RP', 'BROR', 'YERE', 'O3', 'C1', 'C2', 'C3', 'C4', 'C5', 'SA', 'ORRE', 'GRWH', 'REWH', 'B5', 'NT',
        'RT', 'RW', 'BRWH', 'GW', 'P2', 'P4', 'P5', 'M3', 'M4',
    ];

    public function maskForLookup(string $productCode): string
    {
        $upper = strtoupper(trim($productCode));

        if (preg_match('/^([A-Z]{2,4}-?)([A-Z]{2,4})([A-Z])(\/[0-9C.]+)([A-Z0-9]*)$/', $upper, $m) !== 1) {
            return $productCode;
        }

        $prefix = $m[1];
        $variantLetters = $m[2];
        $conductorLetter = $m[3];
        $spec = $m[4];
        $suffix = $m[5];

        $xxxVariant = str_repeat('X', strlen($variantLetters));

        $cleanSuffix = $suffix;
        foreach (self::COLOR_CODES as $color) {
            if (str_ends_with($suffix, $color)) {
                $cleanSuffix = '';
                break;
            }
        }

        return $prefix . $xxxVariant . $conductorLetter . $spec . $cleanSuffix;
    }
}
