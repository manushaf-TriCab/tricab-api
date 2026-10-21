<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\Support\GlbFixture;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Accessory / product-level 3D endpoints: GLB inspection, upload ownership, public `/3d` payload,
 * visual-preview publish gate, accessory specification source and the sanitised public representation.
 *
 * JSON is read through {@see self::at()} / {@see self::text()} so every value has a concrete type for static analysis.
 */
final class Product3DAccessoryEndpointsTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    private const REGION_PUBLIC_ID = '22222222-2222-2222-2222-222222222222';

    private const VARIANT_PUBLIC_ID = '0b1f5c1e-1111-4222-8333-444455556666';

    protected function setUp(): void
    {
        parent::setUp();
        InMemoryProductBlobStore::resetSharedForTests();
    }

    public function test_invalid_glb_marks_model_failed_and_rejects_upload(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $upload = $this->startUpload($app, $productPublicId, 'not-a-glb-payload');
        $this->putBlob($app, $upload, 'not-a-glb-payload');

        $complete = $this->completeUpload($app, $upload['modelPublicId']);
        self::assertSame(422, $complete->getStatusCode(), (string) $complete->getBody());
        self::assertStringContainsString('GLB_BAD_MAGIC', self::text(self::at(TestAppFactory::json($complete), 'error', 'message')));

        $model = $this->getModel($app, $productPublicId, $upload['modelPublicId']);
        self::assertSame('failed', self::at($model, 'statusCode'));
        self::assertSame('failed', self::at($model, 'uploadStatusCode'));

        self::assertSame(422, $this->completeUpload($app, $upload['modelPublicId'])->getStatusCode());
    }

    public function test_complete_upload_refuses_active_unknown_and_deleted_product_models(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $binary = GlbFixture::build();
        $upload = $this->startUpload($app, $productPublicId, $binary);
        $this->putBlob($app, $upload, $binary);

        $first = $this->completeUpload($app, $upload['modelPublicId']);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $again = $this->completeUpload($app, $upload['modelPublicId']);
        self::assertSame(422, $again->getStatusCode());
        self::assertStringContainsString('not awaiting upload', self::text(self::at(TestAppFactory::json($again), 'error', 'message')));

        self::assertSame(422, $this->completeUpload($app, '99999999-9999-9999-9999-999999999999')->getStatusCode());

        $pending = $this->startUpload($app, $productPublicId, $binary);
        $this->putBlob($app, $pending, $binary);
        $detail = TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $productPublicId)));
        $deleted = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/soft-delete', [
            'expectedRowVersion' => self::text(self::at($detail, 'data', 'rowVersion')),
        ]));
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        $orphan = $this->completeUpload($app, $pending['modelPublicId']);
        self::assertSame(404, $orphan->getStatusCode(), (string) $orphan->getBody());
    }

    public function test_public_3d_endpoint_returns_only_three_d_payload_without_caching(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $modelPublicId = $this->seedModel($app, $productPublicId, null, false, true);
        $slug = $this->publishProductWithSlug($app, $productPublicId, 'public-3d-endpoint');

        $beforePublish = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/3d'));
        self::assertSame(200, $beforePublish->getStatusCode(), (string) $beforePublish->getBody());
        self::assertFalse(self::at(TestAppFactory::json($beforePublish), 'data', 'available'));

        $this->publishModel($app, $productPublicId, $modelPublicId, null);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/3d'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = self::at(TestAppFactory::json($response), 'data');
        self::assertIsArray($data);
        self::assertSame(['available', 'primary', 'models'], array_keys($data));
        self::assertTrue(self::at($data, 'available'));
        self::assertSame($modelPublicId, self::at($data, 'primary', 'publicId'));
        $primary = self::at($data, 'primary');
        self::assertIsArray($primary);
        self::assertArrayNotHasKey('storagePath', $primary);

        $embedded = self::at(TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug))), 'data', 'threeD');
        self::assertSame($embedded, $data);

        $missing = $app->handle(TestAppFactory::request('GET', '/api/v1/products/no-such-product-slug/3d'));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('no-store', $missing->getHeaderLine('Cache-Control'));
    }

    public function test_visual_preview_model_requires_explicit_approval_to_publish(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $metadata = [
            'version' => 1,
            'source' => 'specifications',
            'family' => 'accessory',
            'accuracy' => ['class' => 'visual-preview'],
            'assumptions' => ['generic stud'],
            'dimensionsUsed' => ['OVERALL-LENGTH'],
            'accessoryDefinition' => ['kind' => 'cable-lug'],
        ];
        $modelPublicId = $this->seedModel($app, $productPublicId, $metadata, false);
        $model = $this->getModel($app, $productPublicId, $modelPublicId);
        self::assertSame('visual-preview', self::at($model, 'generationMetadata', 'accuracy', 'class'));
        self::assertSame('accessory', self::at($model, 'generationMetadata', 'family'));
        $publishUrl = '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId . '/publish';
        $rowVersion = self::text(self::at($model, 'rowVersion'));

        $refused = $app->handle($this->jsonRequest('POST', $publishUrl, ['expectedRowVersion' => $rowVersion]));
        self::assertSame(422, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertStringContainsString('approveApproximation', self::text(self::at(TestAppFactory::json($refused), 'error', 'message')));

        $blankNote = $app->handle($this->jsonRequest('POST', $publishUrl, [
            'expectedRowVersion' => $rowVersion,
            'approveApproximation' => true,
            'approvalNote' => '   ',
        ]));
        self::assertSame(422, $blankNote->getStatusCode());

        $accepted = $app->handle($this->jsonRequest('POST', $publishUrl, [
            'expectedRowVersion' => $rowVersion,
            'approveApproximation' => true,
            'approvalNote' => 'Approved as indicative only',
        ]));
        self::assertSame(200, $accepted->getStatusCode(), (string) $accepted->getBody());
        self::assertSame('published', self::at(TestAppFactory::json($accepted), 'data', 'statusCode'));

        $audited = null;
        foreach ($this->productStore($app)->auditEvents() as $event) {
            if (($event['actionCode'] ?? '') === 'published' && ($event['entityTypeCode'] ?? '') === 'product-3d-model') {
                $audited = json_decode(self::text($event['newValuesJson'] ?? ''), true);
            }
        }
        self::assertIsArray($audited);
        self::assertTrue(self::at($audited, 'approveApproximation'));
        self::assertSame('Approved as indicative only', self::at($audited, 'approvalNote'));
    }

    public function test_accessory_specification_source_merges_product_and_variant_values(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $sourceUrl = '/api/v1/admin/products/' . $productPublicId . '/3d/accessory-specification-source';

        $missing = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/99999999-9999-9999-9999-999999999999/3d/accessory-specification-source'));
        self::assertSame(404, $missing->getStatusCode());

        $empty = $app->handle(TestAppFactory::request('GET', $sourceUrl));
        self::assertSame(200, $empty->getStatusCode(), (string) $empty->getBody());
        $emptyData = self::at(TestAppFactory::json($empty), 'data');
        self::assertFalse(self::at($emptyData, 'canGenerate'));
        self::assertSame([], self::at($emptyData, 'values'));
        self::assertContains('OVERALL-LENGTH', self::list(self::at($emptyData, 'missing')));

        $group = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-groups', [
            'name' => 'Accessory dims',
            'code' => 'ACCDIMS',
        ])));
        $length = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-definitions', [
            'name' => 'Overall length',
            'code' => 'OVERALL-LENGTH',
            'dataTypeCode' => 'decimal',
            'scopeCode' => 'product',
            'groupPublicId' => self::at($group, 'data', 'publicId'),
            'unit' => 'cm',
        ])));
        $assign = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/specifications', [
            'values' => [['definitionPublicId' => self::at($length, 'data', 'publicId'), 'valueDecimal' => '4.5']],
        ]));
        self::assertSame(200, $assign->getStatusCode(), (string) $assign->getBody());

        $source = $app->handle(TestAppFactory::request('GET', $sourceUrl));
        self::assertSame(200, $source->getStatusCode(), (string) $source->getBody());
        $data = self::at(TestAppFactory::json($source), 'data');
        self::assertTrue(self::at($data, 'canGenerate'));
        self::assertSame(45.0, (float) self::text(self::at($data, 'values', 'OVERALL-LENGTH', 'value')));
        self::assertSame('mm', self::at($data, 'values', 'OVERALL-LENGTH', 'unit'));
        self::assertSame('OVERALL-LENGTH', self::at($data, 'values', 'OVERALL-LENGTH', 'definitionCode'));
        self::assertStringStartsWith('fnv1a32:', self::text(self::at($data, 'sourceHash')));
        self::assertIsArray($data);
        self::assertArrayHasKey('productInternalCode', $data);
    }

    public function test_public_payload_exposes_sanitised_product_level_representation(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $this->seedModel($app, $productPublicId, $this->representationMetadata(), true, true);
        $slug = $this->publishProductWithSlug($app, $productPublicId, 'rep-product');

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/3d'));
        $raw = (string) $response->getBody();
        $expected = [
            'scope' => 'product',
            'accuracyClass' => 'visual-preview',
            'sourceVariantCode' => 'A06-AMXX/6.5H2.5',
            'dimensions' => [
                ['label' => 'Overall length', 'value' => 21.8, 'unit' => 'mm'],
                ['label' => 'Stud hole', 'value' => 6.5, 'unit' => 'mm'],
                ['label' => 'Width', 'value' => 11, 'unit' => 'mm'],
            ],
            'illustrative' => [['label' => 'Plate thickness', 'value' => 0.8, 'unit' => 'mm']],
        ];
        self::assertSame($expected, self::at(self::decode($raw), 'data', 'primary', 'representation'));
        foreach (['sourceHash', 'fnv1a32', 'definitionCode', 'sourceVariantPublicId', 'storagePath', 'basis'] as $needle) {
            self::assertStringNotContainsString($needle, $raw, $needle);
        }

        $embeddedRaw = (string) $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug))->getBody();
        self::assertSame($expected, self::at(self::decode($embeddedRaw), 'data', 'threeD', 'primary', 'representation'));
        foreach (['sourceHash', 'fnv1a32', 'definitionCode', 'sourceVariantPublicId', 'basis'] as $needle) {
            self::assertStringNotContainsString($needle, $embeddedRaw, $needle);
        }
    }

    public function test_draft_representation_model_is_absent_from_public_payload(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $this->seedModel($app, $productPublicId, $this->representationMetadata(), false, true);
        $slug = $this->publishProductWithSlug($app, $productPublicId, 'rep-draft');

        $raw = (string) $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/3d'))->getBody();
        self::assertFalse(self::at(self::decode($raw), 'data', 'available'));
        self::assertStringNotContainsString('representation', $raw);
        self::assertStringNotContainsString('Overall length', $raw);
    }

    public function test_variant_level_model_has_null_representation(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $this->seedModel($app, $productPublicId, $this->representationMetadata(), true, false, self::VARIANT_PUBLIC_ID);
        $slug = $this->publishProductWithSlug($app, $productPublicId, 'rep-variant');

        $data = self::at(TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/3d'))), 'data');
        self::assertTrue(self::at($data, 'available'));
        $first = self::at($data, 'models', 0);
        self::assertIsArray($first);
        self::assertArrayHasKey('representation', $first);
        self::assertNull($first['representation']);
    }

    public function test_cable_style_model_has_null_representation(): void
    {
        $app = $this->createApp();
        $productPublicId = $this->createProduct($app);
        $modelId = $this->seedModel($app, $productPublicId, null, true, true);
        $slug = $this->publishProductWithSlug($app, $productPublicId, 'rep-cable');

        $data = self::at(TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/3d'))), 'data');
        self::assertSame($modelId, self::at($data, 'primary', 'publicId'));
        $primary = self::at($data, 'primary');
        self::assertIsArray($primary);
        self::assertArrayHasKey('representation', $primary);
        self::assertNull($primary['representation']);
    }

    // ---------------------------------------------------------------- helpers (typed)

    /**
     * Walks nested arrays; null when any key is missing or a level is not an array.
     */
    private static function at(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $raw): array
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function representationMetadata(): array
    {
        return [
            'version' => 1,
            'source' => 'specifications',
            'family' => 'ring-terminal-uninsulated',
            'representation' => ['scope' => 'product', 'sourceVariantPublicId' => self::VARIANT_PUBLIC_ID, 'sourceVariantCode' => 'A06-AMXX/6.5H2.5'],
            'accuracy' => ['class' => 'visual-preview'],
            'dimensionsUsed' => [
                'overallLength' => ['label' => 'Overall length', 'valueMm' => 21.8, 'definitionCode' => 'OVERALL-LENGTH', 'source' => 'variant', 'value' => 21.8, 'unit' => 'mm'],
                'studHole' => ['label' => 'Stud hole', 'valueMm' => 6.5, 'definitionCode' => 'STUD-HOLE', 'source' => 'variant', 'value' => 6.5, 'unit' => 'mm'],
                'width' => ['label' => 'Width', 'valueMm' => 11.0, 'definitionCode' => 'WIDTH', 'source' => 'variant', 'value' => 11.0, 'unit' => 'mm'],
            ],
            'assumptions' => [['key' => 'plateThickness', 'label' => 'Plate thickness', 'valueMm' => 0.8, 'basis' => 'x']],
            'sourceHash' => 'fnv1a32:abcd',
        ];
    }

    /**
     * Uploads and completes a model (optionally publishing it, with approval when it is a visual preview).
     *
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $metadata
     */
    private function seedModel(App $app, string $productPublicId, ?array $metadata, bool $publish, bool $primary = false, ?string $variantPublicId = null): string
    {
        $binary = GlbFixture::build();
        $upload = $this->startUpload($app, $productPublicId, $binary, $primary, $metadata);
        $this->putBlob($app, $upload, $binary);
        $complete = $this->completeUpload($app, $upload['modelPublicId']);
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        if ($variantPublicId !== null) {
            $store = $this->productStore($app);
            $map = $store->threeDModelsMap();
            $map[$upload['modelPublicId']]['productVariantPublicId'] = $variantPublicId;
            $store->setThreeDModelsMap($map);
        }
        if ($publish) {
            $accuracy = self::at($metadata, 'accuracy', 'class');
            $this->publishModel($app, $productPublicId, $upload['modelPublicId'], $accuracy === 'visual-preview' ? 'Approved as indicative only' : null);
        }

        return $upload['modelPublicId'];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function publishModel(App $app, string $productPublicId, string $modelPublicId, ?string $approvalNote): void
    {
        $model = $this->getModel($app, $productPublicId, $modelPublicId);
        $body = ['expectedRowVersion' => self::text(self::at($model, 'rowVersion'))];
        if ($approvalNote !== null) {
            $body['approveApproximation'] = true;
            $body['approvalNote'] = $approvalNote;
        }
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId . '/publish', $body));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed>
     */
    private function getModel(App $app, string $productPublicId, string $modelPublicId): array
    {
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $productPublicId . '/3d-models/' . $modelPublicId));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = self::at(TestAppFactory::json($response), 'data');
        self::assertIsArray($data);
        /** @var array<string, mixed> $data */

        return $data;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $metadata
     * @return array{modelPublicId: string, container: string, path: string}
     */
    private function startUpload(App $app, string $productPublicId, string $binary, bool $primary = false, ?array $metadata = null): array
    {
        $body = [
            'originalFileName' => 'model.glb',
            'contentType' => 'model/gltf-binary',
            'byteSize' => strlen($binary),
            'isPrimary' => $primary,
        ];
        if ($metadata !== null) {
            $body['sourceTypeCode'] = 'specification';
            $body['generationMetadata'] = $metadata;
        }
        $initiate = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/3d-models/upload-session', $body));
        self::assertSame(201, $initiate->getStatusCode(), (string) $initiate->getBody());
        $session = self::at(TestAppFactory::json($initiate), 'data');

        return [
            'modelPublicId' => self::text(self::at($session, 'entityPublicId')),
            'container' => self::text(self::at($session, 'storageContainer')),
            'path' => self::text(self::at($session, 'storagePath')),
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array{modelPublicId: string, container: string, path: string} $upload
     */
    private function putBlob(App $app, array $upload, string $binary): void
    {
        $this->blobStore($app)->putObject($upload['container'], $upload['path'], 'model/gltf-binary', $binary);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function completeUpload(App $app, string $modelPublicId): ResponseInterface
    {
        return $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-3d-models/' . $modelPublicId . '/complete-upload', []));
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
        $store = $this->productStore($app);
        $store->addFamily(['publicId' => self::FAMILY_PUBLIC_ID, 'code' => 'FAM', 'name' => 'Test Family', 'displayOrder' => 1, 'isActive' => true]);
        $store->addRegion(['publicId' => self::REGION_PUBLIC_ID, 'code' => 'ANZ', 'name' => 'Australia/NZ', 'displayOrder' => 1, 'isActive' => true]);

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

        return self::text(self::at(TestAppFactory::json($response), 'data', 'publicId'));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function publishProductWithSlug(App $app, string $productPublicId, string $slug): string
    {
        $markets = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/markets', [
            'regionPublicIds' => [self::REGION_PUBLIC_ID],
            'marketPublicIds' => [],
        ]));
        self::assertSame(200, $markets->getStatusCode(), (string) $markets->getBody());

        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $productPublicId));
        $publish = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/publish', [
            'expectedRowVersion' => self::text(self::at(TestAppFactory::json($detail), 'data', 'rowVersion')),
        ]));
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());

        $slugResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/slugs', ['newSlug' => $slug]));
        self::assertSame(200, $slugResponse->getStatusCode(), (string) $slugResponse->getBody());

        return $slug;
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
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}
