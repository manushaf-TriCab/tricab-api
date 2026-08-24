<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

/**
 * Evidence-based legacy pricing mask candidate generation.
 *
 * Rule (cable family): STYLE-TTTT/suffix where TTTT is a 4-char type segment
 * ending in a letter → STYLE-XXX{lastChar}/suffix.
 *
 * Example: RRA-CAXD/10A16BK → RRA-XXXD/10A16BK
 */
final class LegacyPricingMaskGenerator
{
    /**
     * @return list<string>
     */
    public function generateCandidates(string $variantCode): array
    {
        $normalized = strtoupper(trim($variantCode));
        if ($normalized === '' || str_contains($normalized, 'XXX')) {
            return [];
        }

        if (!preg_match('/^([A-Z0-9]{3})-([A-Z0-9]{4})(\/.*)$/', $normalized, $matches)) {
            return [];
        }

        $style = $matches[1];
        $typeSegment = $matches[2];
        $suffix = $matches[3];
        $lastChar = $typeSegment[3];

        if (!ctype_alnum($lastChar)) {
            return [];
        }

        return [$style . '-XXX' . $lastChar . $suffix];
    }
}
