<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\QuoteOrder\Contract\QuoteLineLegacyMappingResolver;

/**
 * Uses variantCode as cata when present. Does NOT invent catdt —
 * requires an injected map for tests / future proven product-type source.
 */
final class VariantCodeOnlyQuoteLineLegacyMappingResolver implements QuoteLineLegacyMappingResolver
{
    /**
     * @param array<string, array{productCode?: string, categoryType: int, colourId?: ?int, packMatrixId?: ?int}> $byVariantPublicId
     */
    public function __construct(
        private readonly array $byVariantPublicId = [],
    ) {
    }

    public function resolve(string $variantPublicId, ?string $variantCode, ?string $length): ?array
    {
        $key = strtolower(trim($variantPublicId));
        if (isset($this->byVariantPublicId[$key])) {
            $row = $this->byVariantPublicId[$key];
            $code = $row['productCode'] ?? $variantCode;
            if ($code === null || trim($code) === '') {
                return null;
            }

            return [
                'productCode' => trim((string) $code),
                'categoryType' => (int) $row['categoryType'],
                'colourId' => $row['colourId'] ?? null,
                'packMatrixId' => isset($row['packMatrixId']) ? (int) $row['packMatrixId'] : null,
            ];
        }

        // Without proven category type, refuse to guess (length→cable is not proven for write).
        return null;
    }
}
