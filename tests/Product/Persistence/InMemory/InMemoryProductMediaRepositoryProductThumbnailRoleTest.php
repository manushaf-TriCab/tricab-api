<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Persistence\InMemory;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductMediaRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryProductMediaRepositoryProductThumbnailRoleTest extends TestCase
{
    public function test_product_thumbnail_role_is_exclusive_per_product(): void
    {
        $store = new InMemoryProductStore();
        $repo = new InMemoryProductMediaRepository($store);
        $productPublicId = ProductPublicId::fromString('11111111-1111-1111-1111-111111111111');
        $actor = UserId::fromInt(1);

        $store->addMedia([
            'publicId' => 'media-a',
            'productPublicId' => $productPublicId->toString(),
            'mediaCategoryCode' => 'gallery',
            'mediaTypeCode' => 'primary-image',
            'title' => 'A',
            'publicUrl' => 'https://cdn.example/a.jpg',
            'isPrimary' => true,
            'displayOrder' => 0,
        ]);
        $store->addMedia([
            'publicId' => 'media-b',
            'productPublicId' => $productPublicId->toString(),
            'mediaCategoryCode' => 'gallery',
            'mediaTypeCode' => 'gallery-image',
            'title' => 'B',
            'publicUrl' => 'https://cdn.example/b.jpg',
            'isPrimary' => false,
            'displayOrder' => 1,
        ]);

        $repo->updateImageRole(
            $productPublicId,
            'media-b',
            ImageRoleCode::PRODUCT_THUMBNAIL,
            $actor,
        );

        $rows = array_values(array_filter(
            $store->media(),
            static fn (array $row): bool => ($row['productPublicId'] ?? '') === $productPublicId->toString(),
        ));

        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['publicId']] = (string) ($row['mediaCategoryCode'] ?? '');
        }

        self::assertSame('gallery', $byId['media-a']);
        self::assertTrue((bool) ($rows[0]['isPrimary'] ?? false) || (bool) ($rows[1]['isPrimary'] ?? false));
        self::assertSame(ImageRoleCode::PRODUCT_THUMBNAIL, $byId['media-b']);

        $repo->updateImageRole(
            $productPublicId,
            'media-a',
            ImageRoleCode::PRODUCT_THUMBNAIL,
            $actor,
        );

        $rows = array_values(array_filter(
            $store->media(),
            static fn (array $row): bool => ($row['productPublicId'] ?? '') === $productPublicId->toString(),
        ));
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['publicId']] = (string) ($row['mediaCategoryCode'] ?? '');
        }

        self::assertSame(ImageRoleCode::PRODUCT_THUMBNAIL, $byId['media-a']);
        self::assertSame('gallery', $byId['media-b']);
    }
}
