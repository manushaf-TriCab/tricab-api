<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;

final class ProductFileUploadEndpointsTest extends TestCase
{
    public function test_initiate_media_upload_session_returns_201(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/media/upload-session',
            [
                'mediaCategoryCode' => 'gallery',
                'mediaTypeCode' => 'primary-image',
                'title' => 'Hero shot',
                'originalFileName' => 'hero.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => 1024,
            ],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['entityPublicId']);
        self::assertIsString($data['uploadUrl']);
        self::assertSame('PUT', $data['httpMethod']);
        self::assertIsString($data['storagePath']);
        self::assertSame('products', $data['storageContainer']);
    }

    public function test_invalid_mime_returns_422(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/media/upload-session',
            [
                'mediaCategoryCode' => 'gallery',
                'mediaTypeCode' => 'primary-image',
                'title' => 'Bad file',
                'originalFileName' => 'notes.txt',
                'contentType' => 'text/plain',
                'byteSize' => 12,
            ],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INVALID_PRODUCT_FILE', $error['code']);
    }

    public function test_oversized_file_returns_422(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/media/upload-session',
            [
                'mediaCategoryCode' => 'gallery',
                'mediaTypeCode' => 'primary-image',
                'title' => 'Huge',
                'originalFileName' => 'huge.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => 30 * 1024 * 1024,
            ],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INVALID_PRODUCT_FILE', $error['code']);
    }

    public function test_put_object_then_complete_upload_succeeds(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);

        $binary = 'fake-jpeg-bytes-for-test';
        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/media/upload-session',
            [
                'mediaCategoryCode' => 'gallery',
                'mediaTypeCode' => 'primary-image',
                'title' => 'Complete me',
                'originalFileName' => 'complete.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => strlen($binary),
            ],
        ));
        self::assertSame(201, $initiate->getStatusCode(), (string) $initiate->getBody());
        $initiated = TestAppFactory::json($initiate);
        /** @var array<string, mixed> $session */
        $session = $initiated['data'];
        $mediaPublicId = (string) $session['entityPublicId'];

        $blobStore = $this->blobStore($app);
        $blobStore->putObject(
            (string) $session['storageContainer'],
            (string) $session['storagePath'],
            'image/jpeg',
            $binary,
        );

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-media/' . $mediaPublicId . '/complete-upload',
            ['binaryBase64' => base64_encode($binary)],
        ));
        $payload = TestAppFactory::json($complete);

        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame($mediaPublicId, $data['publicId']);
        self::assertSame('active', $data['uploadStatus']);
        self::assertSame('clean', $data['scanStatus']);
        self::assertSame(strlen($binary), $data['byteSize']);
    }

    public function test_upload_session_requires_media_manage_permission(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $this->replacePermissions($app, ['account.login', 'PRODUCT.READ', 'PRODUCT.CREATE']);

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/media/upload-session',
            [
                'mediaCategoryCode' => 'gallery',
                'mediaTypeCode' => 'primary-image',
                'title' => 'Denied',
                'originalFileName' => 'denied.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => 10,
            ],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_public_media_excludes_pending_upload(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $slug = 'upload-visibility-product';

        $binary = 'visible-bytes';
        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/media/upload-session',
            [
                'mediaCategoryCode' => 'gallery',
                'mediaTypeCode' => 'primary-image',
                'title' => 'Pending',
                'originalFileName' => 'pending.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => strlen($binary),
            ],
        ));
        self::assertSame(201, $initiate->getStatusCode());
        $session = TestAppFactory::json($initiate)['data'];
        /** @var array<string, mixed> $session */

        // Publish + slug so public endpoint can resolve product.
        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $productPublicId));
        $detailData = TestAppFactory::json($detail)['data'];
        /** @var array<string, mixed> $detailData */
        $markets = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/markets', [
            'regionPublicIds' => ['22222222-2222-2222-2222-222222222222'],
            'marketPublicIds' => [],
        ]));
        self::assertSame(200, $markets->getStatusCode());
        $publish = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/publish', [
            'expectedRowVersion' => $detailData['rowVersion'],
        ]));
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());
        $slugResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/slugs', [
            'newSlug' => $slug,
        ]));
        self::assertSame(200, $slugResponse->getStatusCode(), (string) $slugResponse->getBody());

        $pendingList = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/media'));
        $pendingPayload = TestAppFactory::json($pendingList);
        self::assertSame(200, $pendingList->getStatusCode());
        /** @var array<string, mixed> $pendingData */
        $pendingData = $pendingPayload['data'];
        /** @var list<array<string, mixed>> $pendingItems */
        $pendingItems = $pendingData['items'];
        self::assertSame([], $pendingItems);

        $blobStore = $this->blobStore($app);
        $blobStore->putObject(
            (string) $session['storageContainer'],
            (string) $session['storagePath'],
            'image/jpeg',
            $binary,
        );
        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-media/' . $session['entityPublicId'] . '/complete-upload',
            [],
        ));
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());

        $activeList = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/media'));
        $activePayload = TestAppFactory::json($activeList);
        /** @var array<string, mixed> $activeData */
        $activeData = $activePayload['data'];
        /** @var list<array<string, mixed>> $activeItems */
        $activeItems = $activeData['items'];
        self::assertCount(1, $activeItems);
        self::assertSame($session['entityPublicId'], $activeItems[0]['publicId']);
        self::assertArrayNotHasKey('storagePath', $activeItems[0]);
    }


    public function test_create_360_set_and_reject_incomplete_activation(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);

        $create = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/360-sets',
            [
                'title' => 'Spin set',
                'expectedFrameCount' => 2,
            ],
        ));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $created = TestAppFactory::json($create);
        /** @var array<string, mixed> $set */
        $set = $created['data'];
        $setPublicId = (string) $set['publicId'];

        $activate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-360-sets/' . $setPublicId . '/activate',
            [],
        ));
        $payload = TestAppFactory::json($activate);
        self::assertSame(422, $activate->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('PRODUCT_FILE_UPLOAD_FAILED', $error['code']);
    }

    public function test_360_frame_upload_and_complete(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);

        $create = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/360-sets',
            ['title' => 'Frames', 'expectedFrameCount' => 1],
        ));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $setPublicId = (string) TestAppFactory::json($create)['data']['publicId'];

        $binary = 'frame-bytes';
        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-360-sets/' . $setPublicId . '/frames/upload-session',
            [
                'frameNumber' => 1,
                'originalFileName' => '001.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => strlen($binary),
            ],
        ));
        self::assertSame(201, $initiate->getStatusCode(), (string) $initiate->getBody());
        /** @var array<string, mixed> $session */
        $session = TestAppFactory::json($initiate)['data'];
        $this->blobStore($app)->putObject(
            (string) $session['storageContainer'],
            (string) $session['storagePath'],
            'image/jpeg',
            $binary,
        );

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-360-frames/' . $session['entityPublicId'] . '/complete-upload',
            [],
        ));
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        $completed = TestAppFactory::json($complete)['data'];
        self::assertSame('active', $completed['uploadStatus']);

        $activate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-360-sets/' . $setPublicId . '/activate',
            [],
        ));
        self::assertSame(200, $activate->getStatusCode(), (string) $activate->getBody());
    }

    public function test_ar_model_rejects_invalid_extension(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/ar-models/upload-session',
            [
                'title' => 'Bad AR',
                'originalFileName' => 'model.obj',
                'contentType' => 'model/obj',
                'byteSize' => 100,
            ],
        ));
        $payload = TestAppFactory::json($response);
        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INVALID_PRODUCT_FILE', $error['code']);
    }

    public function test_ar_model_upload_session_and_complete(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $binary = 'glb-bytes-for-test';

        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/ar-models/upload-session',
            [
                'title' => 'Cable AR',
                'originalFileName' => 'cable.glb',
                'contentType' => 'model/gltf-binary',
                'byteSize' => strlen($binary),
                'isPrimary' => true,
            ],
        ));
        self::assertSame(201, $initiate->getStatusCode(), (string) $initiate->getBody());
        /** @var array<string, mixed> $session */
        $session = TestAppFactory::json($initiate)['data'];
        $this->blobStore($app)->putObject(
            (string) $session['storageContainer'],
            (string) $session['storagePath'],
            'model/gltf-binary',
            $binary,
        );

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-ar-models/' . $session['entityPublicId'] . '/complete-upload',
            [],
        ));
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        $completed = TestAppFactory::json($complete)['data'];
        self::assertSame('active', $completed['uploadStatus']);
        self::assertSame('clean', $completed['scanStatus']);
    }

    public function test_document_upload_session_and_complete(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $binary = '%PDF-1.4 test';

        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/documents/upload-session',
            [
                'documentTypeCode' => 'datasheet',
                'title' => 'Datasheet',
                'accessCode' => 'public',
                'originalFileName' => 'sheet.pdf',
                'contentType' => 'application/pdf',
                'byteSize' => strlen($binary),
            ],
        ));
        self::assertSame(201, $initiate->getStatusCode(), (string) $initiate->getBody());
        /** @var array<string, mixed> $session */
        $session = TestAppFactory::json($initiate)['data'];
        $this->blobStore($app)->putObject(
            (string) $session['storageContainer'],
            (string) $session['storagePath'],
            'application/pdf',
            $binary,
        );

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-documents/' . $session['entityPublicId'] . '/complete-upload',
            ['binaryBase64' => base64_encode($binary)],
        ));
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        $completed = TestAppFactory::json($complete)['data'];
        self::assertSame('active', $completed['uploadStatus']);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function createApp(): App
    {
        $app = TestAppFactory::create([
            'BLOB_AUTH_MODE' => 'memory',
            'BLOB_SCAN_MODE' => 'auto-clean',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => 'https://cdn.test.local',
        ]);
        $this->seedReferenceData($app);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createProduct(App $app): string
    {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => 'Upload Test Product',
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
        $store->addRegion([
            'publicId' => '22222222-2222-2222-2222-222222222222',
            'code' => 'ANZ',
            'name' => 'Australia/NZ',
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
     * @param App<ContainerInterface> $app
     */
    private function blobStore(App $app): InMemoryProductBlobStore
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $store = $container->get(ProductBlobStore::class);
        self::assertInstanceOf(InMemoryProductBlobStore::class, $store);

        return $store;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);
        $repository->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}
