<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;
use TriCab\Core\Product\Domain\ValueObject\PresentationMediaRole;

/**
 * Resolves canonical presentation media from ProductImageRoleAssignments first,
 * then legacy ImageRoleCode / IsPrimary fallbacks.
 */
final class PresentationMediaResolver
{
    /**
     * @param list<ProductMediaSummary> $media
     */
    public static function select(
        array $media,
        string $roleCode,
    ): ?ProductMediaSummary {
        $role = PresentationMediaRole::fromString($roleCode)->toString();

        foreach ($media as $item) {
            if (in_array($role, $item->presentationRoles, true)) {
                return $item;
            }
        }

        return match ($role) {
            PresentationMediaRole::PRODUCT_THUMBNAIL => self::legacyThumbnail($media),
            PresentationMediaRole::PRIMARY => self::legacyPrimary($media),
            PresentationMediaRole::PRIMARY_SECONDARY => null,
            PresentationMediaRole::CROSS_SECTION => self::legacyCrossSection($media),
            default => null,
        };
    }

    /**
     * @param list<ProductMediaSummary> $media
     */
    private static function legacyThumbnail(array $media): ?ProductMediaSummary
    {
        foreach ($media as $item) {
            if (strtolower($item->mediaCategoryCode) === ImageRoleCode::PRODUCT_THUMBNAIL) {
                return $item;
            }
        }

        return self::legacyPrimary($media);
    }

    /**
     * @param list<ProductMediaSummary> $media
     */
    private static function legacyPrimary(array $media): ?ProductMediaSummary
    {
        foreach ($media as $item) {
            if ($item->isPrimary) {
                return $item;
            }
        }
        foreach ($media as $item) {
            if (strtolower($item->mediaCategoryCode) === ImageRoleCode::PRIMARY) {
                return $item;
            }
        }
        foreach ($media as $item) {
            if (self::hasUrl($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param list<ProductMediaSummary> $media
     */
    private static function legacyCrossSection(array $media): ?ProductMediaSummary
    {
        $aliases = [
            ImageRoleCode::CROSS_SECTION,
            ImageRoleCode::DIAGRAM,
            'construction',
        ];
        foreach ($media as $item) {
            if (in_array(strtolower($item->mediaCategoryCode), $aliases, true)) {
                return $item;
            }
        }

        return null;
    }

    private static function hasUrl(ProductMediaSummary $item): bool
    {
        $public = trim((string) ($item->publicUrl ?? ''));
        $thumb = trim((string) ($item->thumbnailUrl ?? ''));

        return $public !== '' || $thumb !== '';
    }
}
