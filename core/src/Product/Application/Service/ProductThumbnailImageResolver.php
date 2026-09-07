<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;
use TriCab\Core\Product\Domain\ValueObject\PresentationMediaRole;

/**
 * Resolves the public product-card thumbnail URL from product media.
 *
 * Precedence:
 * 1. ProductImageRoleAssignments product-thumbnail
 * 2. legacy ImageRoleCode product-thumbnail
 * 3. primary image (assignment / IsPrimary / primary role)
 * 4. first valid product image URL
 * 5. null
 */
final class ProductThumbnailImageResolver
{
    /**
     * @param list<ProductMediaSummary> $media
     */
    public static function fromMediaSummaries(array $media): ?string
    {
        $assigned = PresentationMediaResolver::select($media, PresentationMediaRole::PRODUCT_THUMBNAIL);
        if ($assigned !== null) {
            $url = self::canonicalUrl($assigned);
            if ($url !== null) {
                return $url;
            }
        }

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
                'presentationRoles' => $item->presentationRoles,
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
     * @param array{role: string, isPrimary: bool, presentationRoles?: list<string>} $candidate
     */
    private static function rank(array $candidate): int
    {
        $roles = $candidate['presentationRoles'] ?? [];
        if (in_array(PresentationMediaRole::PRODUCT_THUMBNAIL, $roles, true)) {
            return 0;
        }
        if ($candidate['role'] === ImageRoleCode::PRODUCT_THUMBNAIL) {
            return 1;
        }
        if (
            in_array(PresentationMediaRole::PRIMARY, $roles, true)
            || $candidate['isPrimary']
            || $candidate['role'] === ImageRoleCode::PRIMARY
        ) {
            return 2;
        }

        return 3;
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
