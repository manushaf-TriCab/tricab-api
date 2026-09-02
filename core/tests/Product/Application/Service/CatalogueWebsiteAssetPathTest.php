<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\Service\CatalogueWebsiteAssetPath;

final class CatalogueWebsiteAssetPathTest extends TestCase
{
    public function test_normalizes_assests_typo(): void
    {
        self::assertSame('/assets/r6.png', CatalogueWebsiteAssetPath::normalize('/assests/r6.png'));
    }

    public function test_maps_catalogue_asset_when_media_copy_exists(): void
    {
        $exists = static fn (string $kind, string $basename): bool => $kind === 'catalogue' && $basename === 'bval.png';

        self::assertSame(
            '/media/catalogue/bval.png',
            CatalogueWebsiteAssetPath::toPublicUrl('/assets/bval.png', $exists),
        );
        self::assertSame(
            '/media/catalogue/r6.png',
            CatalogueWebsiteAssetPath::toPublicUrl('/assests/r6.png', static fn (string $kind, string $file): bool => $file === 'r6.png'),
        );
    }

    public function test_falls_back_to_normalized_assets_path_when_media_missing(): void
    {
        $missing = static fn (string $kind, string $basename): bool => false;

        self::assertSame('/assets/bval.png', CatalogueWebsiteAssetPath::toPublicUrl('/assets/bval.png', $missing));
        self::assertSame('/assets/r6.png', CatalogueWebsiteAssetPath::toPublicUrl('/assests/r6.png', $missing));
    }

    public function test_maps_certificate_logo_when_media_copy_exists(): void
    {
        $exists = static fn (string $kind, string $basename): bool => $kind === 'certificate' && str_starts_with($basename, 'DNV');

        self::assertSame(
            '/media/certificates/DNV_logo_RGB_tcm8-197625.webp',
            CatalogueWebsiteAssetPath::toPublicUrl('/certificates/DNV_logo_RGB_tcm8-197625.webp', $exists),
        );
    }

    public function test_leaves_absolute_and_media_urls_unchanged(): void
    {
        $exists = static fn (string $kind, string $basename): bool => true;
        $blob = 'https://tricabv2.blob.core.windows.net/products/x.png';

        self::assertSame($blob, CatalogueWebsiteAssetPath::toPublicUrl($blob, $exists));
        self::assertSame('/media/hero.png', CatalogueWebsiteAssetPath::toPublicUrl('/media/hero.png', $exists));
        self::assertNull(CatalogueWebsiteAssetPath::toPublicUrl(null, $exists));
        self::assertNull(CatalogueWebsiteAssetPath::toPublicUrl('  ', $exists));
    }
}
