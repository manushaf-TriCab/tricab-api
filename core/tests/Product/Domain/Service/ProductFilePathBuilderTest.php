<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Domain\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;

final class ProductFilePathBuilderTest extends TestCase
{
    private ProductFilePathBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ProductFilePathBuilder();
    }

    public function test_image_path(): void
    {
        self::assertSame(
            'products/p1/images/m1/hero.png',
            $this->builder->imagePath('p1', 'm1', 'hero.png'),
        );
    }

    public function test_document_path(): void
    {
        self::assertSame(
            'products/p1/documents/d1/spec.pdf',
            $this->builder->documentPath('p1', 'd1', 'spec.pdf'),
        );
    }

    public function test_360_frame_path(): void
    {
        self::assertSame(
            'products/p1/360/s1/3-frame.jpg',
            $this->builder->frame360Path('p1', 's1', 3, 'frame.jpg'),
        );
    }

    public function test_ar_and_temp_paths(): void
    {
        self::assertSame(
            'products/p1/ar/a1/model.glb',
            $this->builder->arPath('p1', 'a1', 'model.glb'),
        );
        self::assertSame(
            'products/p1/tmp/u1/upload.bin',
            $this->builder->tempPath('p1', 'u1', 'upload.bin'),
        );
    }

    public function test_model_3d_and_preview_paths(): void
    {
        self::assertSame(
            'products/p1/3d/m1/model/file.glb',
            $this->builder->model3dPath('p1', 'm1', 'file.glb'),
        );
        self::assertSame(
            'products/p1/3d/m1/preview/preview.webp',
            $this->builder->model3dPreviewPath('p1', 'm1', 'preview.webp'),
        );
        self::assertSame(
            'products/p1/3d/m1/optimized/a1/model.glb',
            $this->builder->model3dOptimizedPath('p1', 'm1', 'a1', 'model.glb'),
        );
        // Path prefix is intentional with container `products` → …/products/products/…
        self::assertStringStartsWith('products/', $this->builder->model3dPreviewPath('p1', 'm1', 'x.webp'));
        self::assertStringNotContainsString('products/products/', $this->builder->model3dPreviewPath('p1', 'm1', 'x.webp'));
        self::assertStringNotContainsString('products/products/', $this->builder->model3dOptimizedPath('p1', 'm1', 'a1', 'model.glb'));
    }

    public function test_style_asset_paths(): void
    {
        self::assertSame(
            'products/styles/s1/images/a1/hero.jpg',
            $this->builder->styleAssetPath('s1', 'images', 'a1', 'hero.jpg'),
        );
        self::assertSame(
            'products/styles/s1/documents/a1/spec.pdf',
            $this->builder->styleAssetPath('s1', 'documents', 'a1', 'spec.pdf'),
        );
        self::assertSame(
            'products/styles/s1/360/a1/frame.jpg',
            $this->builder->styleAssetPath('s1', '360', 'a1', 'frame.jpg'),
        );
        self::assertSame(
            'products/styles/s1/3d/a1/model.glb',
            $this->builder->styleAssetPath('s1', '3d', 'a1', 'model.glb'),
        );
        self::assertSame(
            'products/styles/s1/ar/a1/model.usdz',
            $this->builder->styleAssetPath('s1', 'ar', 'a1', 'model.usdz'),
        );
    }
}
