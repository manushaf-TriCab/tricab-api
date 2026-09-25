<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

/**
 * Detects legacy finalCalculatedCostPermeter PA large-cable branch
 * (coreConfiguration == PA && size >= 120 && coreCount > 1, excluding full-earth styles).
 *
 * Simplified V2 fallback cannot reproduce that branch; Cost API is required.
 */
final class PaLargeCableDetector
{
    /** @var list<string> */
    private const FULL_EARTH_STYLES = [
        'BV', 'DV', 'RR', 'BH', 'PJ', 'HT', 'NJ', 'NT', 'NV', 'NR', 'EZ', 'KK', 'OD', 'KF', 'KP',
    ];

    private const CODE_PATTERN = '/^([A-Z0-9]{2})([A-Z0-9]{0,2})-([A-Z0-9]{2})([A-Z0-9])([A-Z0-9])[\/-](([0-9]{1,2})([CPTQ])([0-9]+)([A-Z]*))$/';

    public function requiresAuthoritativeCostApi(string $productCode): bool
    {
        $code = strtoupper(trim($productCode));
        if ($code === '' || preg_match('/^[A-Z0-9]{2,4}-A/', $code) === 1) {
            return false;
        }

        if (preg_match(self::CODE_PATTERN, $code, $matches) !== 1) {
            return false;
        }

        $style = $matches[1];
        if (in_array($style, self::FULL_EARTH_STYLES, true)) {
            return false;
        }

        $coreConfiguration = $matches[3];
        if ($coreConfiguration !== 'PA') {
            return false;
        }

        $size = (int) $matches[9];
        if ($size < 120) {
            return false;
        }

        return $this->coreCount($matches[7], $matches[8]) > 1;
    }

    private function coreCount(string $countToken, string $coreName): int
    {
        $count = (int) $countToken;

        return match ($coreName) {
            'C' => $count,
            'P' => $count * 2,
            'T' => $count * 3,
            'Q' => $count * 4,
            default => 1,
        };
    }
}
