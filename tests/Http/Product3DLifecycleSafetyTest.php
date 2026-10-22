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
 * Wave 1.6: lifecycle and publication safety for generated (accessory) 3D models.
 * Draft / ready / published separation, failure and interruption states, and the public endpoint exposure.
 */
final class Product3DLifecycleSafetyTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';
    private const REGION_PUBLIC_ID = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        InMemoryProductBlobStore::resetSharedForTests();
    }

    public function test_generating_and_completing_an_upload_never_publishes(): void
    {
        $app = $this->createApp();
        [$product, $slug] = $this->createPublishedProduct($app);
        $model = $this->seed($app, $product, $this->metadata('visual-preview'), true);

        $row = $this->getModel($app, $product, $model);
        self::assertSame('ready', $this->text($row, 'statusCode'));
        self::assertSame('active', $this->text($row, 'uploadStatusCode'));
        self::assertNull($row['publishedAtUtc'] ?? null, 'ready must not mean published');
        self::assertSame(0, $this->publicModelCount($app, $slug), 'a ready draft is not in the public payload');
    }

    public function test_visual_preview_needs_an_approval_note_and_unpublish_removes_it_from_the_public_payload(): void
    {
        $app = $this->createApp();
        [$product, $slug] = $this->createPublishedProduct($app);
        $model = $this->seed($app, $product, $this->metadata('visual-preview'), true);
        $url = '/api/v1/admin/products/' . $product . '/3d-models/' . $model . '/publish';
        $rv = $this->text($this->getModel($app, $product, $model), 'rowVersion');

        self::assertSame(422, $app->handle($this->json('POST', $url, ['expectedRowVersion' => $rv]))->getStatusCode());
        self::assertSame(422, $app->handle($this->json('POST', $url, ['expectedRowVersion' => $rv, 'approveApproximation' => true, 'approvalNote' => '  ']))->getStatusCode());
        self::assertSame(0, $this->publicModelCount($app, $slug));

        $ok = $app->handle($this->json('POST', $url, ['expectedRowVersion' => $rv, 'approveApproximation' => true, 'approvalNote' => 'Indicative only']));
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame(1, $this->publicModelCount($app, $slug));

        $rv2 = $this->text($this->getModel($app, $product, $model), 'rowVersion');
        $un = $app->handle($this->json('POST', '/api/v1/admin/products/' . $product . '/3d-models/' . $model . '/unpublish', ['expectedRowVersion' => $rv2]));
        self::assertSame(200, $un->getStatusCode(), (string) $un->getBody());
        self::assertSame(0, $this->publicModelCount($app, $slug), 'unpublished model disappears from customer-facing responses');
    }

    public function test_accessory_metadata_with_a_missing_or_unknown_accuracy_class_is_treated_as_a_visual_preview(): void
    {
        $app = $this->createApp();
        [$product] = $this->createPublishedProduct($app);
        foreach ([null, 'unknown', 'Dimension-Verified-ish'] as $class) {
            $meta = $this->metadata($class ?? 'visual-preview');
            if ($class === null) {
                unset($meta['accuracy']);
            }
            $model = $this->seed($app, $product, $meta, false);
            $url = '/api/v1/admin/products/' . $product . '/3d-models/' . $model . '/publish';
            $rv = $this->text($this->getModel($app, $product, $model), 'rowVersion');
            self::assertSame(422, $app->handle($this->json('POST', $url, ['expectedRowVersion' => $rv]))->getStatusCode(), 'class=' . var_export($class, true));
        }
    }

    public function test_dimension_verified_accessory_and_plain_uploads_keep_their_existing_publish_behaviour(): void
    {
        $app = $this->createApp();
        [$product] = $this->createPublishedProduct($app);
        $verified = $this->seed($app, $product, $this->metadata('dimension-verified'), false);
        $plain = $this->seed($app, $product, null, false);
        foreach ([$verified, $plain] as $model) {
            $rv = $this->text($this->getModel($app, $product, $model), 'rowVersion');
            $r = $app->handle($this->json('POST', '/api/v1/admin/products/' . $product . '/3d-models/' . $model . '/publish', ['expectedRowVersion' => $rv]));
            self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        }
    }

    public function test_pending_scan_blocks_publication_and_public_exposure(): void
    {
        $app = $this->createApp('pending-scan');
        [$product, $slug] = $this->createPublishedProduct($app);
        $model = $this->seed($app, $product, $this->metadata('dimension-verified'), false);
        $row = $this->getModel($app, $product, $model);
        self::assertSame('pending_scan', $this->text($row, 'scanStatusCode'));
        $r = $app->handle($this->json('POST', '/api/v1/admin/products/' . $product . '/3d-models/' . $model . '/publish', ['expectedRowVersion' => $this->text($row, 'rowVersion')]));
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('scan', strtolower($this->text(TestAppFactory::json($r), 'error', 'message')));
        self::assertSame(0, $this->publicModelCount($app, $slug));
    }

    public function test_failed_inspection_is_failed_not_publishable_and_never_public(): void
    {
        $app = $this->createApp();
        [$product, $slug] = $this->createPublishedProduct($app);
        $upload = $this->startUpload($app, $product, 'not-a-glb', $this->metadata('dimension-verified'));
        $this->blobStore($app)->putObject($upload['container'], $upload['path'], 'model/gltf-binary', 'not-a-glb');
        self::assertSame(422, $this->complete($app, $upload['id'])->getStatusCode());
        $row = $this->getModel($app, $product, $upload['id']);
        self::assertSame('failed', $this->text($row, 'statusCode'));
        $r = $app->handle($this->json('POST', '/api/v1/admin/products/' . $product . '/3d-models/' . $upload['id'] . '/publish', ['expectedRowVersion' => $this->text($row, 'rowVersion')]));
        self::assertSame(422, $r->getStatusCode());
        self::assertSame(0, $this->publicModelCount($app, $slug));
    }

    public function test_interrupted_upload_stays_pending_cannot_complete_or_publish_and_a_retry_is_independent(): void
    {
        $app = $this->createApp();
        [$product, $slug] = $this->createPublishedProduct($app);
        $binary = GlbFixture::build();

        // Interrupted: session created, nothing uploaded.
        $first = $this->startUpload($app, $product, $binary, $this->metadata('visual-preview'));
        $c1 = $this->complete($app, $first['id']);
        self::assertGreaterThanOrEqual(400, $c1->getStatusCode(), 'complete-upload refuses a missing blob');
        $orphan = $this->getModel($app, $product, $first['id']);
        self::assertNotSame('ready', $this->text($orphan, 'statusCode'));
        self::assertNotSame('active', $this->text($orphan, 'uploadStatusCode'));
        $pub = $app->handle($this->json('POST', '/api/v1/admin/products/' . $product . '/3d-models/' . $first['id'] . '/publish', ['expectedRowVersion' => $this->text($orphan, 'rowVersion'), 'approveApproximation' => true, 'approvalNote' => 'x']));
        self::assertSame(422, $pub->getStatusCode(), 'a not-uploaded model can never be published');

        // Partial upload (size mismatch).
        $partial = $this->startUpload($app, $product, $binary, $this->metadata('visual-preview'));
        $this->blobStore($app)->putObject($partial['container'], $partial['path'], 'model/gltf-binary', substr($binary, 0, 20));
        self::assertGreaterThanOrEqual(400, $this->complete($app, $partial['id'])->getStatusCode());
        self::assertNotSame('ready', $this->text($this->getModel($app, $product, $partial['id']), 'statusCode'));

        // Safe retry: a fresh session completes normally; the earlier rows are untouched and stay non-public.
        $retry = $this->startUpload($app, $product, $binary, $this->metadata('visual-preview'));
        $this->blobStore($app)->putObject($retry['container'], $retry['path'], 'model/gltf-binary', $binary);
        self::assertSame(200, $this->complete($app, $retry['id'])->getStatusCode());
        self::assertNotSame($first['id'], $retry['id']);
        self::assertNotSame($first['path'], $retry['path']);
        self::assertSame(0, $this->publicModelCount($app, $slug));
        // The orphaned rows remain visible to admins (they can be soft-deleted) - they are not cleaned up automatically.
        self::assertGreaterThanOrEqual(3, count($this->adminModelIds($app, $product)));
    }

    public function test_repeating_generation_creates_separate_drafts_and_only_the_first_product_model_is_primary(): void
    {
        $app = $this->createApp();
        [$product] = $this->createPublishedProduct($app);
        $a = $this->seed($app, $product, $this->metadata('visual-preview'), false, true);
        $b = $this->seed($app, $product, $this->metadata('visual-preview'), false, false);
        self::assertNotSame($a, $b, 'no de-duplication: every generation is a new draft');
        self::assertTrue((bool) ($this->getModel($app, $product, $a)['isPrimary'] ?? false));
        self::assertFalse((bool) ($this->getModel($app, $product, $b)['isPrimary'] ?? true));
        // A second request for primary while one exists is refused or ignored - never two primaries.
        $c = $this->startUpload($app, $product, GlbFixture::build(), $this->metadata('visual-preview'), true, false);
        $primaries = 0;
        foreach ($this->adminModelIds($app, $product) as $id) {
            if ((bool) ($this->getModel($app, $product, $id)['isPrimary'] ?? false)) {
                ++$primaries;
            }
        }
        self::assertLessThanOrEqual(1, $primaries);
        self::assertNotSame('', $c['id']);
    }

    public function test_public_3d_endpoint_is_unauthenticated_by_design_while_admin_routes_are_not(): void
    {
        $app = TestAppFactory::create([
            'BLOB_AUTH_MODE' => 'memory',
            'BLOB_SCAN_MODE' => 'auto-clean',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'PRODUCT_3D_ENABLED' => 'true',
            'DEV_AUTH_ENABLED' => 'false',
        ]);
        $public = $app->handle(TestAppFactory::request('GET', '/api/v1/products/no-such-product/3d'));
        self::assertSame(404, $public->getStatusCode(), 'reachable without credentials (404 = unknown slug, not 401)');
        self::assertSame('no-store', $public->getHeaderLine('Cache-Control'));
        foreach ([
            ['GET', '/api/v1/admin/products/99999999-9999-9999-9999-999999999999/3d-models'],
            ['POST', '/api/v1/admin/products/99999999-9999-9999-9999-999999999999/3d-models/upload-session'],
            ['POST', '/api/v1/admin/products/99999999-9999-9999-9999-999999999999/3d-models/00000000-0000-0000-0000-000000000000/publish'],
            ['GET', '/api/v1/admin/products/99999999-9999-9999-9999-999999999999/3d/accessory-specification-source'],
        ] as [$method, $path]) {
            self::assertSame(401, $app->handle(TestAppFactory::request($method, $path))->getStatusCode(), $method . ' ' . $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $class): array
    {
        return [
            'version' => 1,
            'source' => 'specifications',
            'family' => 'cable-lug-rectangular-palm',
            'accessoryDefinition' => ['familyKey' => 'cable-lug-rectangular-palm', 'productInternalCode' => 'B37', 'templateId' => 'T21'],
            'representation' => ['scope' => 'product', 'sourceVariantPublicId' => '0b1f5c1e-1111-4222-8333-444455556666', 'sourceVariantCode' => 'B37-ACXX/8H150'],
            'accuracy' => ['class' => $class, 'note' => 'n'],
            'assumptions' => [],
            'dimensionsUsed' => [],
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $meta
     */
    private function seed(App $app, string $product, ?array $meta, bool $unusedPublishFlag, bool $primary = false): string
    {
        $binary = GlbFixture::build();
        $upload = $this->startUpload($app, $product, $binary, $meta, $primary);
        $this->blobStore($app)->putObject($upload['container'], $upload['path'], 'model/gltf-binary', $binary);
        $complete = $this->complete($app, $upload['id']);
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());

        return $upload['id'];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $meta
     * @return array{id: string, container: string, path: string}
     */
    private function startUpload(App $app, string $product, string $binary, ?array $meta, bool $primary = false, bool $assertCreated = true): array
    {
        $body = ['originalFileName' => 'model.glb', 'contentType' => 'model/gltf-binary', 'byteSize' => strlen($binary), 'isPrimary' => $primary];
        if ($meta !== null) {
            $body['sourceTypeCode'] = 'specification';
            $body['generationMetadata'] = $meta;
        }
        $r = $app->handle($this->json('POST', '/api/v1/admin/products/' . $product . '/3d-models/upload-session', $body));
        if ($assertCreated) {
            self::assertSame(201, $r->getStatusCode(), (string) $r->getBody());
        }
        $session = $this->node(TestAppFactory::json($r), 'data');

        return [
            'id' => $this->text($session, 'entityPublicId'),
            'container' => $this->text($session, 'storageContainer'),
            'path' => $this->text($session, 'storagePath'),
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function complete(App $app, string $id): ResponseInterface
    {
        return $app->handle($this->json('POST', '/api/v1/admin/product-3d-models/' . $id . '/complete-upload', []));
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed>
     */
    private function getModel(App $app, string $product, string $id): array
    {
        $r = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $product . '/3d-models/' . $id));
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());

        return $this->node(TestAppFactory::json($r), 'data');
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<string>
     */
    private function adminModelIds(App $app, string $product): array
    {
        $r = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $product . '/3d-models'));
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $data = TestAppFactory::json($r)['data'] ?? [];
        $items = is_array($data) && isset($data['items']) && is_array($data['items']) ? $data['items'] : (is_array($data) ? $data : []);
        $ids = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['publicId']) && is_string($item['publicId'])) {
                $ids[] = $item['publicId'];
            }
        }

        return $ids;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function publicModelCount(App $app, string $slug): int
    {
        $r = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/3d'));
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $models = $this->node(TestAppFactory::json($r), 'data')['models'] ?? [];

        return is_array($models) ? count($models) : 0;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{0: string, 1: string}
     */
    private function createPublishedProduct(App $app): array
    {
        $created = $app->handle($this->json('POST', '/api/v1/admin/products', ['productName' => 'Lifecycle product', 'familyPublicId' => self::FAMILY_PUBLIC_ID]));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $product = $this->text(TestAppFactory::json($created), 'data', 'publicId');
        self::assertSame(200, $app->handle($this->json('PUT', '/api/v1/admin/products/' . $product . '/markets', ['regionPublicIds' => [self::REGION_PUBLIC_ID], 'marketPublicIds' => []]))->getStatusCode());
        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $product));
        $rv = $this->text(TestAppFactory::json($detail), 'data', 'rowVersion');
        self::assertSame(200, $app->handle($this->json('POST', '/api/v1/admin/products/' . $product . '/publish', ['expectedRowVersion' => $rv]))->getStatusCode());
        $slug = 'lifecycle-' . substr(str_replace('-', '', $product), 0, 8);
        self::assertSame(200, $app->handle($this->json('POST', '/api/v1/admin/products/' . $product . '/slugs', ['newSlug' => $slug]))->getStatusCode());

        return [$product, $slug];
    }

    /**
     * @return App<ContainerInterface>
     */
    private function createApp(string $scanMode = 'auto-clean'): App
    {
        $app = TestAppFactory::create([
            'BLOB_AUTH_MODE' => 'memory',
            'BLOB_SCAN_MODE' => $scanMode,
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => 'https://cdn.test.local',
            'PRODUCT_3D_ENABLED' => 'true',
        ]);
        $store = $app->getContainer()->get(InMemoryProductStore::class);
        self::assertInstanceOf(InMemoryProductStore::class, $store);
        $store->addFamily(['publicId' => self::FAMILY_PUBLIC_ID, 'code' => 'FAM', 'name' => 'Test Family', 'displayOrder' => 1, 'isActive' => true]);
        $store->addRegion(['publicId' => self::REGION_PUBLIC_ID, 'code' => 'ANZ', 'name' => 'Australia/NZ', 'displayOrder' => 1, 'isActive' => true]);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function blobStore(App $app): InMemoryProductBlobStore
    {
        $store = $app->getContainer()->get(ProductBlobStore::class);
        self::assertInstanceOf(InMemoryProductBlobStore::class, $store);

        return $store;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function node(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? array_filter($value, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function text(array $payload, string ...$path): string
    {
        $value = $payload;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return '';
            }
            $value = $value[$key];
        }

        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
