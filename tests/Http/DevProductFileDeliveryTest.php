<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Product\Contract\ProductBlobStore;

final class DevProductFileDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        InMemoryProductBlobStore::resetSharedForTests();
    }

    public function test_memory_mode_complete_upload_returns_browser_loadable_dev_url(): void
    {
        $persistDir = sys_get_temp_dir() . '/tricab-dev-blobs-test-' . bin2hex(random_bytes(4));
        $app = $this->createMemoryAppWithoutCdn($persistDir);
        $productPublicId = $this->createProduct($app);
        $binary = $this->tinyJpeg();

        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/media/upload-session',
            [
                'mediaCategoryCode' => 'primary',
                'mediaTypeCode' => 'primary-image',
                'title' => 'Primary',
                'originalFileName' => 'primary.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => strlen($binary),
                'isPrimary' => true,
            ],
        ));
        self::assertSame(201, $initiate->getStatusCode(), (string) $initiate->getBody());
        $session = TestAppFactory::json($initiate)['data'];
        /** @var array<string, mixed> $session */

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-media/' . $session['entityPublicId'] . '/complete-upload',
            ['binaryBase64' => base64_encode($binary)],
        ));
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        $completed = TestAppFactory::json($complete)['data'];
        /** @var array<string, mixed> $completed */

        $expected = ProductDevMediaUrlResolver::PATH_PREFIX . $session['entityPublicId'];
        self::assertSame($expected, $completed['publicUrl']);
        self::assertSame($expected, $completed['thumbnailUrl']);

        // Simulate API process restart: clear in-process map, keep disk files.
        InMemoryProductBlobStore::resetSharedForTests();

        $list = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/media',
        ));
        self::assertSame(200, $list->getStatusCode());
        $items = TestAppFactory::json($list)['data']['items'];
        /** @var list<array<string, mixed>> $items */
        self::assertCount(1, $items);
        self::assertSame($expected, $items[0]['publicUrl']);

        $file = $app->handle(TestAppFactory::request('GET', $expected));
        self::assertSame(200, $file->getStatusCode(), (string) $file->getBody());
        self::assertSame('image/jpeg', $file->getHeaderLine('Content-Type'));
        $file->getBody()->rewind();
        self::assertSame($binary, (string) $file->getBody());
    }

    public function test_missing_memory_bytes_do_not_emit_dev_url(): void
    {
        $app = $this->createMemoryAppWithoutCdn();
        $productPublicId = $this->createProduct($app);
        $binary = $this->tinyJpeg();

        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/media/upload-session',
            [
                'mediaCategoryCode' => 'gallery',
                'mediaTypeCode' => 'primary-image',
                'title' => 'Orphan',
                'originalFileName' => 'orphan.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => strlen($binary),
            ],
        ));
        self::assertSame(201, $initiate->getStatusCode());
        $session = TestAppFactory::json($initiate)['data'];
        /** @var array<string, mixed> $session */

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-media/' . $session['entityPublicId'] . '/complete-upload',
            ['binaryBase64' => base64_encode($binary)],
        ));
        self::assertSame(200, $complete->getStatusCode());

        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $blob = $container->get(ProductBlobStore::class);
        self::assertInstanceOf(InMemoryProductBlobStore::class, $blob);
        $blob->deleteObject((string) $session['storageContainer'], (string) $session['storagePath']);
        InMemoryProductBlobStore::resetSharedForTests();

        $list = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/media',
        ));
        $items = TestAppFactory::json($list)['data']['items'];
        /** @var list<array<string, mixed>> $items */
        self::assertCount(1, $items);
        self::assertNull($items[0]['publicUrl']);
        self::assertNull($items[0]['thumbnailUrl']);
    }

    public function test_production_mode_does_not_expose_dev_file_endpoint(): void
    {
        $app = TestAppFactory::create([
            'APP_ENV' => 'production',
            'USER_REPOSITORY' => 'memory',
            'ALLOW_IN_MEMORY_REPOSITORY_IN_PRODUCTION' => 'true',
            'BLOB_AUTH_MODE' => 'disabled',
            'DEV_AUTH_ENABLED' => 'false',
            'APP_DEBUG' => 'false',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'POSTMARK_SERVER_TOKEN' => 'test-token',
            'POSTMARK_FROM_EMAIL' => 'noreply@tricab.com',
        ]);

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/dev/product-files/00000000-0000-4000-8000-000000000001',
        ));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_connection_string_mode_keeps_cdn_url_not_dev_path(): void
    {
        // Memory adapter with CDN configured still returns CDN URLs (Phase 9 style),
        // not the development delivery path.
        $app = TestAppFactory::create([
            'BLOB_AUTH_MODE' => 'memory',
            'BLOB_SCAN_MODE' => 'auto-clean',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => 'https://cdn.test.local',
        ]);
        $this->seedReferenceData($app);
        $productPublicId = $this->createProduct($app);
        $binary = 'cdn-bytes';

        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/media/upload-session',
            [
                'mediaCategoryCode' => 'gallery',
                'mediaTypeCode' => 'primary-image',
                'title' => 'CDN',
                'originalFileName' => 'cdn.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => strlen($binary),
            ],
        ));
        $session = TestAppFactory::json($initiate)['data'];
        /** @var array<string, mixed> $session */

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-media/' . $session['entityPublicId'] . '/complete-upload',
            ['binaryBase64' => base64_encode($binary)],
        ));
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        $completed = TestAppFactory::json($complete)['data'];
        /** @var array<string, mixed> $completed */
        self::assertIsString($completed['publicUrl']);
        self::assertStringStartsWith('https://cdn.test.local/', (string) $completed['publicUrl']);
        self::assertStringNotContainsString('/api/v1/dev/product-files/', (string) $completed['publicUrl']);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function createMemoryAppWithoutCdn(?string $persistDirectory = null): App
    {
        $app = TestAppFactory::create([
            'APP_ENV' => 'development',
            'BLOB_AUTH_MODE' => 'memory',
            'BLOB_SCAN_MODE' => 'auto-clean',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => '',
        ]);
        $this->seedReferenceData($app);

        if ($persistDirectory !== null) {
            $container = $app->getContainer();
            self::assertInstanceOf(ContainerInterface::class, $container);
            if (method_exists($container, 'set')) {
                $container->set(
                    ProductBlobStore::class,
                    new InMemoryProductBlobStore(
                        cdnBaseUrl: '',
                        defaultContainer: 'products',
                        persistDirectory: $persistDirectory,
                    ),
                );
            }
        }

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createProduct(App $app): string
    {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => 'Dev File Product',
            'familyPublicId' => '11111111-1111-1111-1111-111111111111',
        ]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = TestAppFactory::json($response);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        return (string) $data['publicId'];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedReferenceData(App $app): void
    {
        $store = $this->productStore($app);
        $store->addFamily([
            'publicId' => '11111111-1111-1111-1111-111111111111',
            'code' => 'FAM',
            'name' => 'Test Family',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function productStore(App $app): InMemoryProductStore
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $store = $container->get(InMemoryProductStore::class);
        self::assertInstanceOf(InMemoryProductStore::class, $store);

        return $store;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    private function tinyJpeg(): string
    {
        // Minimal valid JPEG header + payload bytes for Content-Type delivery tests.
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . "\xFF\xD9";
    }
}
