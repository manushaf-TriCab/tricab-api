<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Commerce\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Commerce\Application\Service\CartLineMediaPicker;

final class CartLineMediaPickerTest extends TestCase
{
    public function test_prefers_variant_specific_primary_over_product_primary(): void
    {
        $picked = CartLineMediaPicker::pick([
            $this->row('v1', 'p1', 'https://cdn.example/product-primary.jpg', isPrimary: true, isVariantSpecific: false),
            $this->row('v1', 'p1', 'https://cdn.example/variant-primary.jpg', isPrimary: true, isVariantSpecific: true, role: 'thumbnail', mediaPublicId: 'm-var'),
        ]);

        self::assertSame('https://cdn.example/variant-primary.jpg', $picked['v1']->imageUrl);
        self::assertSame('p1', $picked['v1']->productPublicId);
    }

    public function test_falls_back_to_product_primary_when_variant_has_no_image(): void
    {
        $picked = CartLineMediaPicker::pick([
            $this->row('v1', 'p-bva', 'https://cdn.example/bva-primary.jpg', isPrimary: true, isVariantSpecific: false),
            $this->row('v1', 'p-bva', 'https://cdn.example/bva-gallery.jpg', isPrimary: false, isVariantSpecific: false, role: 'gallery', displayOrder: 2, mediaPublicId: 'm-gal'),
        ]);

        self::assertSame('https://cdn.example/bva-primary.jpg', $picked['v1']->imageUrl);
        self::assertSame('p-bva', $picked['v1']->productPublicId);
    }

    public function test_product_thumbnail_role_beats_primary_for_cart_cards(): void
    {
        $picked = CartLineMediaPicker::pick([
            $this->row('v1', 'p-m3p', 'https://cdn.example/cable.jpg', isPrimary: true, isVariantSpecific: false, role: 'primary', mediaPublicId: 'm-cable'),
            $this->row('v1', 'p-m3p', 'https://cdn.example/section.png', isPrimary: false, isVariantSpecific: false, role: 'cross-section', displayOrder: 2, mediaPublicId: 'm-section')
                + ['hasProductThumbnailRole' => true],
        ]);

        self::assertSame('https://cdn.example/section.png', $picked['v1']->imageUrl);
    }

    public function test_falls_back_to_gallery_when_no_primary(): void
    {
        $picked = CartLineMediaPicker::pick([
            $this->row('v1', 'p1', 'https://cdn.example/gallery.jpg', isPrimary: false, isVariantSpecific: false, role: 'gallery'),
        ]);

        self::assertSame('https://cdn.example/gallery.jpg', $picked['v1']->imageUrl);
    }

    public function test_returns_null_image_when_no_usable_url(): void
    {
        $picked = CartLineMediaPicker::pick([
            $this->row('v1', 'p1', null, isPrimary: true, isVariantSpecific: false),
        ]);

        self::assertSame('p1', $picked['v1']->productPublicId);
        self::assertNull($picked['v1']->imageUrl);
    }

    public function test_uses_thumbnail_public_url_when_public_url_missing(): void
    {
        $picked = CartLineMediaPicker::pick([
            [
                'variantPublicId' => 'v1',
                'productPublicId' => 'p1',
                'mediaPublicId' => 'm1',
                'publicUrl' => null,
                'thumbnailPublicUrl' => 'https://cdn.example/thumb.jpg',
                'isPrimary' => true,
                'isVariantSpecific' => false,
                'imageRoleCode' => 'primary',
                'displayOrder' => 0,
            ],
        ]);

        self::assertSame('https://cdn.example/thumb.jpg', $picked['v1']->imageUrl);
    }

    public function test_does_not_throw_for_empty_rows(): void
    {
        self::assertSame([], CartLineMediaPicker::pick([]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $variantPublicId,
        string $productPublicId,
        ?string $publicUrl,
        bool $isPrimary,
        bool $isVariantSpecific,
        string $role = 'primary',
        int $displayOrder = 0,
        string $mediaPublicId = 'm-primary',
    ): array {
        return [
            'variantPublicId' => $variantPublicId,
            'productPublicId' => $productPublicId,
            'mediaPublicId' => $mediaPublicId,
            'publicUrl' => $publicUrl,
            'thumbnailPublicUrl' => null,
            'isPrimary' => $isPrimary,
            'isVariantSpecific' => $isVariantSpecific,
            'imageRoleCode' => $role,
            'displayOrder' => $displayOrder,
        ];
    }
}
