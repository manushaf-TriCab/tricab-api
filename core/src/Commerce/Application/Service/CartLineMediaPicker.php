<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Commerce\Application\DTO\CartLineMedia;

/**
 * Picks one canonical Product Media URL per variant from a batched candidate set.
 *
 * Fallback order:
 * 1. Variant-specific primary/thumbnail
 * 2. Other variant-specific media
 * 3. Product primary image
 * 4. Product thumbnail/primary/hero role
 * 5. Any remaining product-level public URL (customer-view gallery fallback)
 * 6. null
 */
final class CartLineMediaPicker
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, CartLineMedia> keyed by variant public ID
     */
    public static function pick(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $variantPublicId = self::string($row['variantPublicId'] ?? '');
            if ($variantPublicId === '') {
                continue;
            }
            $grouped[$variantPublicId][] = $row;
        }

        $out = [];
        foreach ($grouped as $variantPublicId => $candidates) {
            $productPublicId = null;
            foreach ($candidates as $row) {
                $pid = self::nullableString($row['productPublicId'] ?? null);
                if ($pid !== null) {
                    $productPublicId = $pid;
                    break;
                }
            }

            usort($candidates, self::compare(...));

            $chosen = null;
            foreach ($candidates as $row) {
                if (self::canonicalUrl($row) !== null) {
                    $chosen = $row;
                    break;
                }
            }

            $out[$variantPublicId] = new CartLineMedia(
                productPublicId: $productPublicId,
                imageUrl: $chosen !== null ? self::canonicalUrl($chosen) : null,
                mediaPublicId: $chosen !== null ? self::nullableString($chosen['mediaPublicId'] ?? null) : null,
                storageContainer: $chosen !== null ? self::nullableString($chosen['storageContainer'] ?? null) : null,
                storagePath: $chosen !== null ? self::nullableString($chosen['storagePath'] ?? null) : null,
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function canonicalUrl(array $row): ?string
    {
        $publicUrl = self::nullableString($row['publicUrl'] ?? null);
        if ($publicUrl !== null) {
            return $publicUrl;
        }

        return self::nullableString($row['thumbnailPublicUrl'] ?? null);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function compare(array $a, array $b): int
    {
        $rank = self::rank($a) <=> self::rank($b);
        if ($rank !== 0) {
            return $rank;
        }

        $order = self::int($a['displayOrder'] ?? 0) <=> self::int($b['displayOrder'] ?? 0);
        if ($order !== 0) {
            return $order;
        }

        return self::string($a['mediaPublicId'] ?? '') <=> self::string($b['mediaPublicId'] ?? '');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function rank(array $row): int
    {
        if (self::canonicalUrl($row) === null) {
            return 99;
        }

        $variantSpecific = self::bool($row['isVariantSpecific'] ?? false);
        $isPrimary = self::bool($row['isPrimary'] ?? false);
        $role = strtolower(self::string($row['imageRoleCode'] ?? ''));

        if ($variantSpecific && ($isPrimary || in_array($role, ['thumbnail', 'primary'], true))) {
            return 1;
        }
        if ($variantSpecific) {
            return 2;
        }
        if ($isPrimary) {
            return 3;
        }
        if (in_array($role, ['thumbnail', 'primary', 'hero'], true)) {
            return 4;
        }

        return 5;
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private static function nullableString(mixed $value): ?string
    {
        $normalized = self::string($value);

        return $normalized === '' ? null : $normalized;
    }

    private static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return self::int($value) === 1;
    }
}
