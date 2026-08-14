<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Storage;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Product\Storage\AzureProductBlobStore;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Product\Contract\ProductMediaRepository;

final class ProductDevMediaUrlResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        InMemoryProductBlobStore::resetSharedForTests();
        parent::tearDown();
    }

    public function test_azure_mode_issues_read_only_sas_for_private_blob(): void
    {
        $settings = $this->azureSettings(300);
        $blob = new RecordingBlobStore();
        $resolver = new ProductDevMediaUrlResolver(
            $settings,
            $this->createStub(ProductMediaRepository::class),
            $blob,
        );

        $stored = 'https://tricabv2.blob.core.windows.net/products/products/abc/images/def/image.jpg';
        $resolved = $resolver->resolvePublicUrl(
            $stored,
            'def',
            'products',
            'products/abc/images/def/image.jpg',
        );

        self::assertNotNull($resolved);
        self::assertNotSame($stored, $resolved);
        self::assertStringStartsWith('https://tricabv2.blob.core.windows.net/products/products/', $resolved);
        $query = $this->query($resolved);
        self::assertSame('r', $query['sp'] ?? null);
        self::assertSame('b', $query['sr'] ?? null);
        self::assertSame('https', $query['spr'] ?? null);
        self::assertArrayHasKey('sig', $query);
        self::assertSame(300, $blob->lastTtl);
        self::assertStringNotContainsString('AccountKey', $resolved);
    }

    public function test_azure_mode_parses_unsigned_blob_url_when_path_missing(): void
    {
        $settings = $this->azureSettings(180);
        $blob = new RecordingBlobStore();
        $resolver = new ProductDevMediaUrlResolver(
            $settings,
            $this->createStub(ProductMediaRepository::class),
            $blob,
        );

        $stored = 'https://tricabv2.blob.core.windows.net/products/products/abc/images/def/image.jpg';
        $resolved = $resolver->resolvePublicUrl($stored, 'def');

        self::assertNotNull($resolved);
        self::assertSame('products', $blob->lastContainer);
        self::assertSame('products/abc/images/def/image.jpg', $blob->lastPath);
        self::assertSame(180, $blob->lastTtl);
        self::assertSame('r', $this->query($resolved)['sp'] ?? null);
    }

    public function test_read_sas_expiry_follows_configured_ttl(): void
    {
        $settings = $this->azureSettings(120);
        $store = new AzureProductBlobStore($settings);
        $resolver = new ProductDevMediaUrlResolver(
            $settings,
            $this->createStub(ProductMediaRepository::class),
            $store,
        );

        $before = time();
        $resolved = $resolver->resolvePublicUrl(
            'https://tricabv2.blob.core.windows.net/products/products/x/images/y/a.jpg',
            'y',
            'products',
            'products/x/images/y/a.jpg',
        );
        $after = time();

        self::assertNotNull($resolved);
        $expiry = strtotime((string) ($this->query($resolved)['se'] ?? ''));
        self::assertIsInt($expiry);
        self::assertGreaterThanOrEqual($before + 120 - 5, $expiry);
        self::assertLessThanOrEqual($after + 120 + 5, $expiry);
    }

    public function test_cdn_urls_are_not_signed(): void
    {
        $settings = $this->azureSettings(300, 'https://cdn.test.local');
        $blob = new RecordingBlobStore();
        $resolver = new ProductDevMediaUrlResolver(
            $settings,
            $this->createStub(ProductMediaRepository::class),
            $blob,
        );

        $stored = 'https://cdn.test.local/products/products/abc/images/def/image.jpg';
        $resolved = $resolver->resolvePublicUrl(
            $stored,
            'def',
            'products',
            'products/abc/images/def/image.jpg',
        );

        self::assertSame($stored, $resolved);
        self::assertNull($blob->lastTtl);
    }

    public function test_memory_mode_still_uses_dev_file_path_when_bytes_exist(): void
    {
        $settings = Settings::fromEnvironment($this->baseEnv([
            'BLOB_AUTH_MODE' => 'memory',
            'BLOB_CDN_BASE_URL' => '',
            'APP_ENV' => 'development',
        ]));
        $blob = new InMemoryProductBlobStore(cdnBaseUrl: '', defaultContainer: 'products');
        $blob->putObject('products', 'products/abc/images/def/image.jpg', 'image/jpeg', 'bytes');

        $record = new \TriCab\Core\Product\Application\DTO\ProductMediaFileRecord(
            publicId: 'def',
            productPublicId: 'abc',
            mediaCategoryCode: 'primary',
            mediaTypeCode: 'primary-image',
            title: 'Primary',
            publicUrl: null,
            altText: null,
            displayOrder: 1,
            isPrimary: true,
            uploadStatusCode: 'active',
            scanStatusCode: 'not_required',
            byteSize: 5,
            contentType: 'image/jpeg',
            originalFileName: 'image.jpg',
            fileExtension: 'jpg',
            widthPx: null,
            heightPx: null,
            thumbnailUrl: null,
            storagePath: 'products/abc/images/def/image.jpg',
            storageContainer: 'products',
        );
        $media = $this->createStub(ProductMediaRepository::class);
        $media->method('findByPublicId')->willReturn($record);

        $resolver = new ProductDevMediaUrlResolver($settings, $media, $blob);
        $resolved = $resolver->resolvePublicUrl(null, 'def');

        self::assertSame(ProductDevMediaUrlResolver::PATH_PREFIX . 'def', $resolved);
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function azureSettings(int $ttl, string $cdn = ''): Settings
    {
        $key = base64_encode(str_repeat('c', 32));

        return Settings::fromEnvironment($this->baseEnv([
            'BLOB_AUTH_MODE' => 'connection-string',
            'BLOB_ACCOUNT_NAME' => 'tricabv2',
            'BLOB_ACCOUNT_KEY' => $key,
            'BLOB_ENDPOINT' => 'https://tricabv2.blob.core.windows.net',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => $cdn,
            'BLOB_DOWNLOAD_SAS_TTL_SECONDS' => (string) $ttl,
        ]));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function baseEnv(array $overrides): array
    {
        return array_merge([
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'true',
            'DEV_AUTH_ENABLED' => 'true',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
        ], $overrides);
    }
}
