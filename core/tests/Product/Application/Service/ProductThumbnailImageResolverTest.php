<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Application\Service\ProductThumbnailImageResolver;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;

final class ProductThumbnailImageResolverTest extends TestCase
{
    public function test_prefers_product_thumbnail_role(): void
    {
        $url = ProductThumbnailImageResolver::fromMediaSummaries([
            $this->media('primary', 'https://cdn.example/primary.jpg', isPrimary: true),
            $this->media(ImageRoleCode::PRODUCT_THUMBNAIL, 'https://cdn.example/thumb.jpg'),
        ]);

        self::assertSame('https://cdn.example/thumb.jpg', $url);
    }

    public function test_falls_back_to_primary_when_no_product_thumbnail(): void
    {
        $url = ProductThumbnailImageResolver::fromMediaSummaries([
            $this->media('gallery', 'https://cdn.example/gallery.jpg'),
            $this->media('primary', 'https://cdn.example/primary.jpg', isPrimary: true),
        ]);

        self::assertSame('https://cdn.example/primary.jpg', $url);
    }

    public function test_falls_back_to_first_valid_image(): void
    {
        $url = ProductThumbnailImageResolver::fromMediaSummaries([
            $this->media('gallery', 'https://cdn.example/first.jpg'),
            $this->media('gallery', 'https://cdn.example/second.jpg'),
        ]);

        self::assertSame('https://cdn.example/first.jpg', $url);
    }

    private function media(
        string $role,
        string $publicUrl,
        bool $isPrimary = false,
        int $displayOrder = 0,
    ): ProductMediaSummary {
        return new ProductMediaSummary(
            publicId: 'm-' . md5($publicUrl),
            mediaCategoryCode: $role,
            mediaTypeCode: 'primary-image',
            title: 'Image',
            publicUrl: $publicUrl,
            altText: null,
            displayOrder: $displayOrder,
            isPrimary: $isPrimary,
        );
    }
}
