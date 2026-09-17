<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\QuoteOrder\Contract\QuoteLineLegacyMappingResolver;
use TriCab\Core\QuoteOrder\Domain\Mapping\LegacyQuoteLineTypeDefaults;

/**
 * First-write mapper: simple cable lines only.
 *
 * - cata = authoritative variantCode (not display title)
 * - catdt = 1615 when length present (cable semantics)
 * - col = 4008 (proven QuoteManagement create hardcode)
 * - pm = 1501 (proven cable pack-matrix descript id — NOT margin)
 *
 * Accessories / length-less lines → null (unsupported for Phase 4C first write).
 */
final class SimpleCableQuoteLineLegacyMappingResolver implements QuoteLineLegacyMappingResolver
{
    /**
     * @param array<string, array{productCode?: string, categoryType: int, colourId?: ?int, packMatrixId?: ?int}> $overrides
     */
    public function __construct(
        private readonly array $overrides = [],
        private readonly ?QuoteLineLegacyMappingResolver $fallback = null,
    ) {
    }

    public function resolve(string $variantPublicId, ?string $variantCode, ?string $length): ?array
    {
        $key = strtolower(trim($variantPublicId));
        if ($key !== '' && isset($this->overrides[$key])) {
            $row = $this->overrides[$key];
            $code = $row['productCode'] ?? $variantCode;
            if ($code === null || trim((string) $code) === '') {
                return null;
            }

            return [
                'productCode' => trim((string) $code),
                'categoryType' => (int) $row['categoryType'],
                'colourId' => $row['colourId'] ?? LegacyQuoteLineTypeDefaults::LEGACY_CREATE_COLOUR_ID,
                'packMatrixId' => $row['packMatrixId'] ?? LegacyQuoteLineTypeDefaults::CABLE_PACK_MATRIX_ID,
            ];
        }

        $code = $variantCode !== null ? trim($variantCode) : '';
        if ($code === '') {
            return $this->fallback?->resolve($variantPublicId, $variantCode, $length);
        }

        // First-write scope: length-bearing cable lines only.
        if ($length === null || trim($length) === '' || !is_numeric($length) || bccomp($length, '0', 6) <= 0) {
            return $this->fallback?->resolve($variantPublicId, $variantCode, $length);
        }

        // Accessory-style codes (…-A…) remain unsupported even with length.
        if ($this->looksLikeAccessoryCode($code)) {
            return null;
        }

        return [
            'productCode' => $code,
            'categoryType' => LegacyQuoteLineTypeDefaults::CABLE_CATEGORY_TYPE,
            'colourId' => LegacyQuoteLineTypeDefaults::LEGACY_CREATE_COLOUR_ID,
            'packMatrixId' => LegacyQuoteLineTypeDefaults::CABLE_PACK_MATRIX_ID,
        ];
    }

    private function looksLikeAccessoryCode(string $productCode): bool
    {
        // Legacy 4JS/import heuristic: dash at pos 2|3 and next segment starts with A → accessory.
        $dash = strpos($productCode, '-');
        if ($dash !== 2 && $dash !== 3) {
            return false;
        }
        $rest = substr($productCode, $dash + 1);

        return $rest !== false && $rest !== '' && strtoupper($rest[0]) === 'A';
    }
}
