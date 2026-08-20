<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

/**
 * Maps price source codes to ErpSourceReference without adding schema columns.
 * Convention: source codes use prefix "source:"; ERP references are stored verbatim.
 */
final class PricingSourceCodec
{
    private const PREFIX = 'source:';

    public static function encode(?string $sourceCode): ?string
    {
        if ($sourceCode === null || trim($sourceCode) === '') {
            return null;
        }

        $normalized = strtolower(trim($sourceCode));
        if (in_array($normalized, ['erp', 'legacy-tc', 'imported'], true) && str_starts_with($sourceCode, self::PREFIX)) {
            return $sourceCode;
        }

        return self::PREFIX . $normalized;
    }

    public static function decode(?string $erpSourceReference): ?string
    {
        if ($erpSourceReference === null || trim($erpSourceReference) === '') {
            return 'manual';
        }

        if (str_starts_with(strtolower($erpSourceReference), self::PREFIX)) {
            return substr($erpSourceReference, strlen(self::PREFIX));
        }

        return 'erp';
    }
}
