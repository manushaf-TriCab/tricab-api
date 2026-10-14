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
        $dedicated = self::firstMatch(
            $media,
            static fn (ProductMediaSummary $item): bool => in_array(
                PresentationMediaRole::PRODUCT_THUMBNAIL,
                $item->presentationRoles,
                true,
            ),
        );
        if ($dedicated !== null) {
            return $dedicated;
        }

        $legacy = self::firstMatch(
            $media,
            static fn (ProductMediaSummary $item): bool => strtolower($item->mediaCategoryCode) === ImageRoleCode::PRODUCT_THUMBNAIL,
        );
        if ($legacy !== null) {
            return $legacy;
        }

        $primary = self::firstMatch(
            $media,
            static fn (ProductMediaSummary $item): bool => $item->isPrimary
                || strtolower($item->mediaCategoryCode) === ImageRoleCode::PRIMARY
                || in_array(PresentationMediaRole::PRIMARY, $item->presentationRoles, true),
        );
        if ($primary !== null) {
            return $primary;
        }

        return self::firstMatch($media, static fn (ProductMediaSummary $item): bool => true);
    }

    /**
     * @param list<ProductMediaSummary> $media
     * @param callable(ProductMediaSummary): bool $matches
     */
    private static function firstMatch(array $media, callable $matches): ?string
    {
        $hits = [];
        foreach ($media as $item) {
            if (!$matches($item) || self::canonicalUrl($item) === null) {
                continue;
            }
            $hits[] = $item;
        }
        if ($hits === []) {
            return null;
        }

        usort($hits, static function (ProductMediaSummary $a, ProductMediaSummary $b): int {
            $order = $a->displayOrder <=> $b->displayOrder;
            if ($order !== 0) {
                return $order;
            }

            return strcmp($a->publicId, $b->publicId);
        });

        return self::canonicalUrl($hits[0]);
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
