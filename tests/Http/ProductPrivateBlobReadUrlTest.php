<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\Admin\ProductManagementDtoSerializer;
use TriCab\Api\Product\Storage\AzureProductBlobStore;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Contract\ProductMediaRepository;

final class ProductPrivateBlobReadUrlTest extends TestCase
{
    public function test_admin_media_response_uses_signed_read_url_without_mutating_canonical_fields(): void
    {
        $resolver = $this->azureResolver();
        $canonical = 'https://tricabv2.blob.core.windows.net/products/products/p1/images/m1/image.jpg';
        $summary = new ProductMediaSummary(
            publicId: 'm1',
            mediaCategoryCode: 'primary',
            mediaTypeCode: 'primary-image',
            title: 'Primary',
            publicUrl: $canonical,
            altText: null,
            displayOrder: 1,
            isPrimary: true,
            byteSize: 338,
            contentType: 'image/jpeg',
            originalFileName: 'image.jpg',
            storagePath: 'products/p1/images/m1/image.jpg',
            storageContainer: 'products',
        );

        $payload = ProductManagementDtoSerializer::mediaSummary($summary, true, $resolver);

        self::assertSame('products', $payload['storageContainer']);
        self::assertSame('products/p1/images/m1/image.jpg', $payload['storagePath']);
        self::assertSame('image/jpeg', $payload['contentType']);
        self::assertSame(338, $payload['byteSize']);
        self::assertIsString($payload['publicUrl']);
        self::assertStringStartsWith($canonical . '?', (string) $payload['publicUrl']);
        $query = $this->query((string) $payload['publicUrl']);
        self::assertSame('r', $query['sp'] ?? null);
        self::assertSame($canonical, $summary->publicUrl);
        self::assertStringNotContainsString('sig=', $summary->publicUrl ?? '');
    }

    public function test_public_media_response_uses_signed_read_url_and_omits_storage_fields(): void
    {
        $resolver = $this->azureResolver();
        $canonical = 'https://tricabv2.blob.core.windows.net/products/products/p1/images/m1/image.jpg';
        $summary = new ProductMediaSummary(
            publicId: 'm1',
            mediaCategoryCode: 'primary',
            mediaTypeCode: 'primary-image',
            title: 'Primary',
            publicUrl: $canonical,
            altText: null,
            displayOrder: 1,
            isPrimary: true,
            storagePath: 'products/p1/images/m1/image.jpg',
            storageContainer: 'products',
        );

        $payload = ProductManagementDtoSerializer::publicMediaSummary($summary, $resolver);

        self::assertArrayNotHasKey('storageContainer', $payload);
        self::assertArrayNotHasKey('storagePath', $payload);
        self::assertIsString($payload['publicUrl']);
        self::assertStringContainsString('sp=r', (string) $payload['publicUrl']);
        self::assertSame($canonical, $summary->publicUrl);
    }

    private function azureResolver(): ProductDevMediaUrlResolver
    {
        $key = base64_encode(str_repeat('d', 32));
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'true',
            'DEV_AUTH_ENABLED' => 'true',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'BLOB_AUTH_MODE' => 'connection-string',
            'BLOB_ACCOUNT_NAME' => 'tricabv2',
            'BLOB_ACCOUNT_KEY' => $key,
            'BLOB_ENDPOINT' => 'https://tricabv2.blob.core.windows.net',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => '',
            'BLOB_DOWNLOAD_SAS_TTL_SECONDS' => '300',
        ]);

        return new ProductDevMediaUrlResolver(
            $settings,
            $this->createStub(ProductMediaRepository::class),
            new AzureProductBlobStore($settings),
        );
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
