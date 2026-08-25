<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

/**
 * Canonical relative blob paths for product files (no container prefix).
 *
 * Azure container is typically `products`. Paths intentionally start with
 * `products/{productPublicId}/…`, so public URLs look like:
 *   …/products/products/{productPublicId}/…
 * That duplication matches Product Media / documents and is intentional —
 * do not strip the path prefix for new 3D assets.
 */
final class ProductFilePathBuilder
{
    public function imagePath(string $productPublicId, string $mediaPublicId, string $safeFileName): string
    {
        return sprintf('products/%s/images/%s/%s', $productPublicId, $mediaPublicId, $safeFileName);
    }

    public function documentPath(string $productPublicId, string $documentPublicId, string $safeFileName): string
    {
        return sprintf('products/%s/documents/%s/%s', $productPublicId, $documentPublicId, $safeFileName);
    }

    public function frame360Path(
        string $productPublicId,
        string $setPublicId,
        int $frameNumber,
        string $safeFileName,
    ): string {
        return sprintf(
            'products/%s/360/%s/%d-%s',
            $productPublicId,
            $setPublicId,
            $frameNumber,
            $safeFileName,
        );
    }

    public function arPath(string $productPublicId, string $mediaPublicId, string $safeFileName): string
    {
        return sprintf('products/%s/ar/%s/%s', $productPublicId, $mediaPublicId, $safeFileName);
    }

    public function model3dPath(string $productPublicId, string $modelPublicId, string $safeFileName): string
    {
        return sprintf('products/%s/3d/%s/model/%s', $productPublicId, $modelPublicId, $safeFileName);
    }

    public function model3dPreviewPath(string $productPublicId, string $modelPublicId, string $safeFileName): string
    {
        return sprintf('products/%s/3d/%s/preview/%s', $productPublicId, $modelPublicId, $safeFileName);
    }

    public function model3dSourcePath(string $productPublicId, string $modelPublicId, string $safeFileName): string
    {
        return sprintf('products/%s/3d/%s/source/%s', $productPublicId, $modelPublicId, $safeFileName);
    }

    /**
     * Optimised GLB lives under a separate asset folder — never overwrite the original model path.
     */
    public function model3dOptimizedPath(
        string $productPublicId,
        string $modelPublicId,
        string $assetPublicId,
        string $safeFileName,
    ): string {
        return sprintf(
            'products/%s/3d/%s/optimized/%s/%s',
            $productPublicId,
            $modelPublicId,
            $assetPublicId,
            $safeFileName,
        );
    }

    public function tempPath(string $productPublicId, string $uploadSessionPublicId, string $safeFileName): string
    {
        return sprintf('products/%s/tmp/%s/%s', $productPublicId, $uploadSessionPublicId, $safeFileName);
    }

    /**
     * Style-owned blobs live under products/styles/… so they share the products
     * container and existing SAS/read infrastructure without a second store.
     */
    public function styleAssetPath(
        string $stylePublicId,
        string $kind,
        string $assetPublicId,
        string $safeFileName,
    ): string {
        $normalizedKind = match (strtolower(trim($kind))) {
            'image', 'images' => 'images',
            'document', 'documents' => 'documents',
            '360', 'spin_360', 'spin360' => '360',
            '3d', 'models_3d', 'model3d' => '3d',
            'ar', 'ar_assets' => 'ar',
            default => throw new \InvalidArgumentException('Unsupported style storage kind: ' . $kind),
        };

        return sprintf(
            'products/styles/%s/%s/%s/%s',
            $stylePublicId,
            $normalizedKind,
            $assetPublicId,
            $safeFileName,
        );
    }
}
