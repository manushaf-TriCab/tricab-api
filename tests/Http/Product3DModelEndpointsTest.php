<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Product\Contract\ProductBlobStore;

final class Product3DModelEndpointsTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    private const REGION_PUBLIC_ID = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        InMemoryProductBlobStore::resetSharedForTests();
    }

    public function test_list_create_complete_update_publish_primary_unpublish_and_public_excludes_draft(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $binary = 'glb-bytes-for-3d-studio-test';

        $listEmpty = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models',
        ));
        self::assertSame(200, $listEmpty->getStatusCode(), (string) $listEmpty->getBody());
        $listEmptyPayload = TestAppFactory::json($listEmpty);
        self::assertSame([], $listEmptyPayload['data']['items']);

        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/upload-session',
            [
                'name' => 'Studio Cable',
                'originalFileName' => 'cable.glb',
                'contentType' => 'model/gltf-binary',
                'byteSize' => strlen($binary),
                'isPrimary' => true,
            ],
        ));
        self::assertSame(201, $initiate->getStatusCode(), (string) $initiate->getBody());
        /** @var array<string, mixed> $session */
        $session = TestAppFactory::json($initiate)['data'];
        $modelPublicId = (string) $session['entityPublicId'];
        self::assertSame('3d-model', $session['fileKind']);
        self::assertStringContainsString('/3d/', (string) $session['storagePath']);

        $this->blobStore($app)->putObject(
            (string) $session['storageContainer'],
            (string) $session['storagePath'],
            'model/gltf-binary',
            $binary,
        );

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-3d-models/' . $modelPublicId . '/complete-upload',
            [],
        ));
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        /** @var array<string, mixed> $completed */
        $completed = TestAppFactory::json($complete)['data'];
        self::assertSame('ready', $completed['statusCode']);
        self::assertSame('active', $completed['uploadStatusCode']);
        self::assertSame('clean', $completed['scanStatusCode']);
        self::assertTrue($completed['isPrimary']);
        $rowVersion = (string) $completed['rowVersion'];

        $update = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId,
            [
                'expectedRowVersion' => $rowVersion,
                'name' => 'Studio Cable Updated',
                'viewerConfiguration' => [
                    'version' => 1,
                    'controls' => ['autoRotate' => false],
                ],
            ],
        ));
        self::assertSame(200, $update->getStatusCode(), (string) $update->getBody());
        /** @var array<string, mixed> $updated */
        $updated = TestAppFactory::json($update)['data'];
        self::assertSame('Studio Cable Updated', $updated['name']);
        self::assertFalse($updated['viewerConfiguration']['controls']['autoRotate']);
        $rowVersion = (string) $updated['rowVersion'];

        $conflict = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId . '/publish',
            ['expectedRowVersion' => 'AAAAAAAAAAA='],
        ));
        self::assertSame(409, $conflict->getStatusCode(), (string) $conflict->getBody());
        $conflictPayload = TestAppFactory::json($conflict);
        self::assertSame('PRODUCT_CONCURRENCY_CONFLICT', $conflictPayload['error']['code']);

        $publish = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId . '/publish',
            ['expectedRowVersion' => $rowVersion],
        ));
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());
        /** @var array<string, mixed> $published */
        $published = TestAppFactory::json($publish)['data'];
        self::assertSame('published', $published['statusCode']);
        $rowVersion = (string) $published['rowVersion'];

        $setPrimary = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId . '/set-primary',
            ['expectedRowVersion' => $rowVersion],
        ));
        self::assertSame(200, $setPrimary->getStatusCode(), (string) $setPrimary->getBody());
        /** @var array<string, mixed> $primary */
        $primary = TestAppFactory::json($setPrimary)['data'];
        self::assertTrue($primary['isPrimary']);
        $rowVersion = (string) $primary['rowVersion'];

        $get = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId,
        ));
        self::assertSame(200, $get->getStatusCode());
        $getPayload = TestAppFactory::json($get)['data'];
        self::assertSame($modelPublicId, $getPayload['publicId']);
        self::assertArrayHasKey('storagePath', $getPayload);

        $slug = $this->publishProductWithSlug($app, $productPublicId, (string) TestAppFactory::json(
            $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $productPublicId)),
        )['data']['rowVersion'], 'studio-cable-3d');

        $public = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug));
        self::assertSame(200, $public->getStatusCode(), (string) $public->getBody());
        /** @var array<string, mixed> $publicData */
        $publicData = TestAppFactory::json($public)['data'];
        self::assertArrayHasKey('threeD', $publicData);
        self::assertTrue($publicData['threeD']['available']);
        self::assertSame($modelPublicId, $publicData['threeD']['primary']['publicId']);
        self::assertArrayNotHasKey('storagePath', $publicData['threeD']['primary']);
        self::assertArrayNotHasKey('scanStatusCode', $publicData['threeD']['primary']);
        self::assertArrayNotHasKey('generationMetadata', $publicData['threeD']['primary']);

        $unpublish = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId . '/unpublish',
            ['expectedRowVersion' => $rowVersion],
        ));
        self::assertSame(200, $unpublish->getStatusCode(), (string) $unpublish->getBody());
        self::assertSame('ready', TestAppFactory::json($unpublish)['data']['statusCode']);

        $publicAfter = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug));
        self::assertSame(200, $publicAfter->getStatusCode());
        /** @var array<string, mixed> $publicAfterData */
        $publicAfterData = TestAppFactory::json($publicAfter)['data'];
        self::assertFalse($publicAfterData['threeD']['available']);
        self::assertNull($publicAfterData['threeD']['primary']);
        self::assertSame([], $publicAfterData['threeD']['models']);
    }

    public function test_soft_delete_hides_model_from_list(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $binary = 'glb-soft-delete';

        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/upload-session',
            [
                'originalFileName' => 'gone.glb',
                'contentType' => 'model/gltf-binary',
                'byteSize' => strlen($binary),
            ],
        ));
        /** @var array<string, mixed> $session */
        $session = TestAppFactory::json($initiate)['data'];
        $modelPublicId = (string) $session['entityPublicId'];
        $this->blobStore($app)->putObject(
            (string) $session['storageContainer'],
            (string) $session['storagePath'],
            'model/gltf-binary',
            $binary,
        );
        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-3d-models/' . $modelPublicId . '/complete-upload',
            [],
        ));
        $rowVersion = (string) TestAppFactory::json($complete)['data']['rowVersion'];

        $deleted = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId . '/soft-delete',
            ['expectedRowVersion' => $rowVersion],
        ));
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertSame('archived', TestAppFactory::json($deleted)['data']['statusCode']);

        $list = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models',
        ));
        self::assertSame([], TestAppFactory::json($list)['data']['items']);
    }

    public function test_specification_source_and_procedural_upload_metadata(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);

        $missingProduct = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/99999999-9999-9999-9999-999999999999/3d/specification-source',
        ));
        self::assertSame(404, $missingProduct->getStatusCode());

        $empty = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/3d/specification-source',
        ));
        self::assertSame(200, $empty->getStatusCode(), (string) $empty->getBody());
        $emptyData = TestAppFactory::json($empty)['data'];
        self::assertSame('none', $emptyData['detailLevel']);
        self::assertFalse($emptyData['canGenerate']);
        self::assertContains('overallDiameterMm', $emptyData['missing']);

        $group = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-groups', [
            'name' => 'Dims',
            'code' => 'DIMS3D',
        ])))['data'];
        $od = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-definitions', [
            'name' => 'Nom Cable OD',
            'code' => 'NOM-CABLE-OD',
            'dataTypeCode' => 'decimal',
            'scopeCode' => 'product',
            'groupPublicId' => $group['publicId'],
            'unit' => 'mm',
        ])))['data'];
        $cores = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-definitions', [
            'name' => 'Cores',
            'code' => 'CORES',
            'dataTypeCode' => 'integer',
            'scopeCode' => 'product',
            'groupPublicId' => $group['publicId'],
        ])))['data'];
        $cond = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-definitions', [
            'name' => 'Cond OD',
            'code' => 'NOM-COND-OD',
            'dataTypeCode' => 'decimal',
            'scopeCode' => 'product',
            'groupPublicId' => $group['publicId'],
            'unit' => 'mm',
        ])))['data'];
        $ins = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-definitions', [
            'name' => 'Ins OD',
            'code' => 'NOM-INS-OD',
            'dataTypeCode' => 'decimal',
            'scopeCode' => 'product',
            'groupPublicId' => $group['publicId'],
            'unit' => 'mm',
        ])))['data'];

        $assign = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/specifications', [
            'values' => [
                ['definitionPublicId' => $od['publicId'], 'valueDecimal' => '28.4'],
                ['definitionPublicId' => $cores['publicId'], 'valueInteger' => 4],
                ['definitionPublicId' => $cond['publicId'], 'valueDecimal' => '5.2'],
                ['definitionPublicId' => $ins['publicId'], 'valueDecimal' => '7.4'],
            ],
        ]));
        self::assertSame(200, $assign->getStatusCode(), (string) $assign->getBody());

        $source = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/3d/specification-source',
        ));
        self::assertSame(200, $source->getStatusCode(), (string) $source->getBody());
        $data = TestAppFactory::json($source)['data'];
        self::assertSame('detailed', $data['detailLevel']);
        self::assertTrue($data['canGenerate']);
        self::assertSame(28.4, $data['values']['overallDiameterMm']['value']);
        self::assertSame('NOM-CABLE-OD', $data['values']['overallDiameterMm']['definitionCode']);
        self::assertStringStartsWith('fnv1a32:', $data['sourceHash']);
        self::assertArrayNotHasKey('VOLTAGE', $data['values']);

        $binary = 'glb-procedural-bytes';
        $initiate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $productPublicId . '/3d-models/upload-session',
            [
                'name' => 'Product — Cutaway',
                'originalFileName' => 'model.glb',
                'contentType' => 'model/gltf-binary',
                'byteSize' => strlen($binary),
                'sourceTypeCode' => 'specification',
                'generationMetadata' => [
                    'version' => 1,
                    'source' => 'specifications',
                    'style' => 'cutaway',
                    'detailLevel' => 'detailed',
                    'sourceHash' => $data['sourceHash'],
                    'specificationSnapshot' => [
                        'overallDiameterMm' => [
                            'definitionCode' => 'NOM-CABLE-OD',
                            'value' => 28.4,
                            'unit' => 'mm',
                        ],
                    ],
                ],
            ],
        ));
        self::assertSame(201, $initiate->getStatusCode(), (string) $initiate->getBody());
        $session = TestAppFactory::json($initiate)['data'];
        $this->blobStore($app)->putObject(
            (string) $session['storageContainer'],
            (string) $session['storagePath'],
            'model/gltf-binary',
            $binary,
        );
        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-3d-models/' . $session['entityPublicId'] . '/complete-upload',
            [],
        ));
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        $completed = TestAppFactory::json($complete)['data'];
        self::assertSame('specification', $completed['sourceTypeCode']);
        self::assertSame('ready', $completed['statusCode']);
        self::assertNull($completed['publishedAtUtc'] ?? null);
        self::assertSame($data['sourceHash'], $completed['generationMetadata']['sourceHash']);
        self::assertSame('cutaway', $completed['generationMetadata']['style']);
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
            'PRODUCT_3D_ENABLED' => 'true',
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
            'productName' => '3D Studio Test Product',
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) TestAppFactory::json($response)['data']['publicId'];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function publishProductWithSlug(App $app, string $productPublicId, string $rowVersion, string $slug): string
    {
        $markets = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/markets', [
            'regionPublicIds' => [self::REGION_PUBLIC_ID],
            'marketPublicIds' => [],
        ]));
        self::assertSame(200, $markets->getStatusCode(), (string) $markets->getBody());

        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $productPublicId));
        $rowVersion = (string) TestAppFactory::json($detail)['data']['rowVersion'];

        $publish = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/publish', [
            'expectedRowVersion' => $rowVersion,
        ]));
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());

        $slugResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/slugs', [
            'newSlug' => $slug,
        ]));
        self::assertSame(200, $slugResponse->getStatusCode(), (string) $slugResponse->getBody());

        return $slug;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedReferenceData(App $app): void
    {
        $store = $this->productStore($app);
        $store->addFamily([
            'publicId' => self::FAMILY_PUBLIC_ID,
            'code' => 'FAM',
            'name' => 'Test Family',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
        $store->addRegion([
            'publicId' => self::REGION_PUBLIC_ID,
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
