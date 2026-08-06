<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

/**
 * Canonical relative blob paths for product files (no container prefix).
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

    public function tempPath(string $productPublicId, string $uploadSessionPublicId, string $safeFileName): string
    {
        return sprintf('products/%s/tmp/%s/%s', $productPublicId, $uploadSessionPublicId, $safeFileName);
    }
}
