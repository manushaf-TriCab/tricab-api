<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;

/**
 * Resolves the public product-card thumbnail URL from product media.
 *
 * Precedence:
 * 1. product-thumbnail role
 * 2. primary image (IsPrimary or primary role)
 * 3. first valid product image URL
 * 4. null
 */
final class ProductThumbnailImageResolver
{
    /**
     * @param list<ProductMediaSummary> $media
     */
    public static function fromMediaSummaries(array $media): ?string
    {
        $candidates = [];
        foreach ($media as $item) {
            $url = self::canonicalUrl($item);
            if ($url === null) {
                continue;
            }
            $candidates[] = [
                'url' => $url,
                'role' => strtolower($item->mediaCategoryCode),
                'isPrimary' => $item->isPrimary,
                'displayOrder' => $item->displayOrder,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            $rank = self::rank($a) <=> self::rank($b);
            if ($rank !== 0) {
                return $rank;
            }

            $order = $a['displayOrder'] <=> $b['displayOrder'];
            if ($order !== 0) {
                return $order;
            }

            return strcmp($a['url'], $b['url']);
        });

        return $candidates[0]['url'];
    }

    /**
     * @param array{role: string, isPrimary: bool} $candidate
     */
    private static function rank(array $candidate): int
    {
        if ($candidate['role'] === ImageRoleCode::PRODUCT_THUMBNAIL) {
            return 0;
        }
        if ($candidate['isPrimary'] || $candidate['role'] === ImageRoleCode::PRIMARY) {
            return 1;
        }

        return 2;
    }

    private static function canonicalUrl(ProductMediaSummary $item): ?string
    {
        $publicUrl = self::nullableString($item->publicUrl);
        if ($publicUrl !== null) {
            return $publicUrl;
        }

        return self::nullableString($item->thumbnailUrl);
    }

    private static function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
