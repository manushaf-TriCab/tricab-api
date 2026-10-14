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

    public function test_presentation_role_beats_primary_and_is_ordered_by_display_order(): void
    {
        $later = $this->media('cross-section', 'https://cdn.example/later.png', displayOrder: 2)
            ->withPresentationRoles(['product-thumbnail']);
        $earlier = $this->media('cross-section', 'https://cdn.example/earlier.png', displayOrder: 1)
            ->withPresentationRoles(['product-thumbnail']);
        $primary = $this->media('primary', 'https://cdn.example/primary.jpg', isPrimary: true);

        $url = ProductThumbnailImageResolver::fromMediaSummaries([$primary, $later, $earlier]);

        self::assertSame('https://cdn.example/earlier.png', $url);
    }

    public function test_ignores_a_thumbnail_role_with_no_url(): void
    {
        $empty = $this->media('cross-section', '')->withPresentationRoles(['product-thumbnail']);
        $primary = $this->media('primary', 'https://cdn.example/primary.jpg', isPrimary: true);

        $url = ProductThumbnailImageResolver::fromMediaSummaries([$empty, $primary]);

        self::assertSame('https://cdn.example/primary.jpg', $url);
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
