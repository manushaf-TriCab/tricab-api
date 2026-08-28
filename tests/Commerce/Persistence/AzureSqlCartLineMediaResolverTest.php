<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Commerce\Persistence;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Commerce\Persistence\AzureSqlCartLineMediaResolver;
use TriCab\Api\Infrastructure\Database\SqlConnection;

final class AzureSqlCartLineMediaResolverTest extends TestCase
{
    public function test_batches_one_query_and_prefers_variant_image(): void
    {
        $connection = $this->createMock(SqlConnection::class);
        $connection->expects(self::once())
            ->method('fetchAll')
            ->willReturn([
                [
                    'VariantPublicId' => 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA',
                    'ProductPublicId' => 'BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB',
                    'MediaPublicId' => 'media-product',
                    'PublicUrl' => 'https://cdn.example/product.jpg',
                    'ThumbnailPublicUrl' => null,
                    'StorageContainer' => 'products',
                    'StoragePath' => 'products/p/product.jpg',
                    'IsPrimary' => 1,
                    'ImageRoleCode' => 'primary',
                    'DisplayOrder' => 0,
                    'IsVariantSpecific' => 0,
                ],
                [
                    'VariantPublicId' => 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA',
                    'ProductPublicId' => 'BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB',
                    'MediaPublicId' => 'media-variant',
                    'PublicUrl' => 'https://cdn.example/variant.jpg',
                    'ThumbnailPublicUrl' => null,
                    'StorageContainer' => 'products',
                    'StoragePath' => 'products/p/variant.jpg',
                    'IsPrimary' => 1,
                    'ImageRoleCode' => 'thumbnail',
                    'DisplayOrder' => 0,
                    'IsVariantSpecific' => 1,
                ],
            ]);

        $resolver = new AzureSqlCartLineMediaResolver($connection);
        $result = $resolver->resolveForVariantPublicIds(['AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA']);

        self::assertSame('https://cdn.example/variant.jpg', $result['AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA']->imageUrl);
        self::assertSame('BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB', $result['AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA']->productPublicId);
    }

    public function test_falls_back_to_product_primary(): void
    {
        $connection = $this->createMock(SqlConnection::class);
        $connection->expects(self::once())
            ->method('fetchAll')
            ->willReturn([
                [
                    'VariantPublicId' => 'F4994365-1492-F111-B336-000D3AE07ABC',
                    'ProductPublicId' => '9970E6F0-F091-F111-B337-000D3AE094E4',
                    'MediaPublicId' => 'media-bva',
                    'PublicUrl' => 'https://cdn.example/bva.jpg',
                    'ThumbnailPublicUrl' => null,
                    'StorageContainer' => 'products',
                    'StoragePath' => 'products/bva/primary.jpg',
                    'IsPrimary' => 1,
                    'ImageRoleCode' => 'primary',
                    'DisplayOrder' => 0,
                    'IsVariantSpecific' => 0,
                ],
            ]);

        $resolver = new AzureSqlCartLineMediaResolver($connection);
        $result = $resolver->resolveForVariantPublicIds(['F4994365-1492-F111-B336-000D3AE07ABC']);

        self::assertSame(
            'https://cdn.example/bva.jpg',
            $result['F4994365-1492-F111-B336-000D3AE07ABC']->imageUrl,
        );
        self::assertSame(
            '9970E6F0-F091-F111-B337-000D3AE094E4',
            $result['F4994365-1492-F111-B336-000D3AE07ABC']->productPublicId,
        );
    }

    public function test_returns_null_image_without_throwing_when_media_missing(): void
    {
        $connection = $this->createMock(SqlConnection::class);
        $connection->expects(self::once())
            ->method('fetchAll')
            ->willReturn([
                [
                    'VariantPublicId' => 'v-none',
                    'ProductPublicId' => 'p-none',
                    'MediaPublicId' => null,
                    'PublicUrl' => null,
                    'ThumbnailPublicUrl' => null,
                    'StorageContainer' => null,
                    'StoragePath' => null,
                    'IsPrimary' => 0,
                    'ImageRoleCode' => null,
                    'DisplayOrder' => 0,
                    'IsVariantSpecific' => 0,
                ],
            ]);

        $resolver = new AzureSqlCartLineMediaResolver($connection);
        $result = $resolver->resolveForVariantPublicIds(['v-none']);

        self::assertSame('p-none', $result['v-none']->productPublicId);
        self::assertNull($result['v-none']->imageUrl);
    }

    public function test_skips_query_when_no_variant_ids(): void
    {
        $connection = $this->createMock(SqlConnection::class);
        $connection->expects(self::never())->method('fetchAll');

        $resolver = new AzureSqlCartLineMediaResolver($connection);
        self::assertSame([], $resolver->resolveForVariantPublicIds([]));
    }
}
