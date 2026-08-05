<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

final class AdminProductEndpointsTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    private const REGION_PUBLIC_ID = '22222222-2222-2222-2222-222222222222';

    private const RULE_SET_PUBLIC_ID = '33333333-3333-3333-3333-333333333333';

    private const NONEXISTENT_PUBLIC_ID = '44444444-4444-4444-8444-444444444444';

    public function test_admin_product_routes_require_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('UNAUTHENTICATED', $error['code']);
    }

    public function test_admin_product_routes_require_permission(): void
    {
        $app = TestAppFactory::create();
        $this->stripAdminPermissions($app);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_create_product_success(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => 'Industrial Widget',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('draft', $data['statusCode']);
        self::assertIsString($data['publicId']);
        self::assertIsString($data['rowVersion']);
        self::assertArrayNotHasKey('productId', $data);
    }

    public function test_list_products_returns_items_and_pagination(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createProduct($app, 'Listed Product');

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['items']);
        self::assertIsArray($data['pagination']);

        /** @var list<array<string, mixed>> $items */
        $items = $data['items'];
        $product = self::findByPublicId($items, $publicId);
        self::assertNotNull($product);
        self::assertArrayHasKey('rowVersion', $product);
    }

    public function test_get_product_returns_detail_with_row_version(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createProduct($app, 'Detail Product');

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $publicId));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame($publicId, $data['publicId']);
        self::assertSame('Detail Product', $data['productName']);
        self::assertIsString($data['rowVersion']);
    }

    public function test_update_product_with_expected_row_version(): void
    {
        $app = TestAppFactory::create();
        $created = $this->createProductPayload($app, 'Original Name');

        $response = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/products/' . $created['publicId'], [
            'expectedRowVersion' => $created['rowVersion'],
            'productName' => 'Updated Name',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertNotSame($created['rowVersion'], $data['rowVersion']);

        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $created['publicId']));
        $detailPayload = TestAppFactory::json($detail);
        /** @var array<string, mixed> $detailData */
        $detailData = $detailPayload['data'];
        self::assertSame('Updated Name', $detailData['productName']);
    }

    public function test_update_product_concurrency_conflict_returns_409(): void
    {
        $app = TestAppFactory::create();
        $created = $this->createProductPayload($app, 'Concurrency Product');
        $staleRowVersion = $created['rowVersion'];

        $first = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/products/' . $created['publicId'], [
            'expectedRowVersion' => $staleRowVersion,
            'productName' => 'First Update',
        ]));
        self::assertSame(200, $first->getStatusCode());

        $second = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/products/' . $created['publicId'], [
            'expectedRowVersion' => $staleRowVersion,
            'productName' => 'Second Update',
        ]));
        $payload = TestAppFactory::json($second);

        self::assertSame(409, $second->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('PRODUCT_CONCURRENCY_CONFLICT', $error['code']);
    }

    public function test_archive_is_distinct_from_soft_delete(): void
    {
        $app = TestAppFactory::create();
        $created = $this->createProductPayload($app, 'Archive Product');

        $archive = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $created['publicId'] . '/archive',
            ['expectedRowVersion' => $created['rowVersion']],
        ));
        self::assertSame(200, $archive->getStatusCode());

        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $created['publicId']));
        $detailPayload = TestAppFactory::json($detail);
        self::assertSame(200, $detail->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $detailPayload['data'];
        self::assertTrue($data['isArchived']);
    }

    public function test_soft_deleted_product_is_not_found(): void
    {
        $app = TestAppFactory::create();
        $created = $this->createProductPayload($app, 'Delete Product');

        $deleted = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $created['publicId'] . '/soft-delete',
            ['expectedRowVersion' => $created['rowVersion']],
        ));
        self::assertSame(200, $deleted->getStatusCode());

        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $created['publicId']));
        $payload = TestAppFactory::json($detail);

        self::assertSame(404, $detail->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('PRODUCT_NOT_FOUND', $error['code']);
    }

    public function test_publish_not_ready_returns_422(): void
    {
        $app = TestAppFactory::create();
        $created = $this->createProductPayload($app, 'Unready Product');

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $created['publicId'] . '/publish',
            ['expectedRowVersion' => $created['rowVersion']],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('PRODUCT_NOT_READY_FOR_PUBLICATION', $error['code']);
    }

    public function test_publication_readiness_endpoint(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createProduct($app, 'Readiness Product');

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $publicId . '/publication-readiness',
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertFalse($data['isReady']);
        self::assertIsArray($data['errors']);
        self::assertIsArray($data['warnings']);
        self::assertIsArray($data['checks']);
    }

    public function test_create_variant_and_duplicate_sku_conflict(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createProduct($app, 'Variant Product');

        $first = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $publicId . '/variants', [
            'variantName' => 'Standard',
            'sku' => 'SKU-DUP-001',
        ]));
        self::assertSame(201, $first->getStatusCode());

        $second = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $publicId . '/variants', [
            'variantName' => 'Duplicate SKU',
            'sku' => 'SKU-DUP-001',
        ]));
        $payload = TestAppFactory::json($second);

        self::assertSame(409, $second->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('DUPLICATE_VARIANT_SKU', $error['code']);
    }

    public function test_preview_product_code(): void
    {
        $app = TestAppFactory::create();
        $this->seedCodeRuleSet($app);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-codes/preview', [
            'ruleSetPublicId' => self::RULE_SET_PUBLIC_ID,
            'segmentValues' => ['series' => 'X1'],
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('TC-X1', $data['generatedCode']);
        self::assertTrue($data['isUnique']);
    }

    public function test_get_pricing_requires_pricing_view_permission(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createProduct($app, 'Pricing Product');
        $this->replacePermissions($app, array_values(array_filter(
            $this->allProductPermissions(),
            static fn (string $code): bool => $code !== 'PRODUCT.PRICING.VIEW',
        )));

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $publicId . '/pricing'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_get_audit_requires_audit_view_permission(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createProduct($app, 'Audit Product');
        $this->replacePermissions($app, array_values(array_filter(
            $this->allProductPermissions(),
            static fn (string $code): bool => $code !== 'PRODUCT.AUDIT.VIEW',
        )));

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $publicId . '/audit'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_list_products_invalid_sort_returns_422(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products?sortBy=not-a-sort'));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('VALIDATION_FAILED', $error['code']);
    }

    /**
     * @param App<ContainerInterface> $app
     *
     * @return array{publicId: string, rowVersion: string}
     */
    private function createProductPayload(App $app, string $productName): array
    {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $productName,
        ]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = TestAppFactory::json($response);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        return [
            'publicId' => (string) $data['publicId'],
            'rowVersion' => (string) $data['rowVersion'],
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createProduct(App $app, string $productName): string
    {
        return $this->createProductPayload($app, $productName)['publicId'];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedCodeRuleSet(App $app): void
    {
        $store = $this->productStore($app);
        $store->addRuleSet([
            'publicId' => self::RULE_SET_PUBLIC_ID,
            'code' => 'DEFAULT',
            'name' => 'Default Rule Set',
            'isActive' => true,
            'segments' => [
                [
                    'segmentCode' => 'prefix',
                    'segmentName' => 'Prefix',
                    'segmentTypeCode' => 'literal',
                    'literalValue' => 'TC',
                    'separatorAfter' => '-',
                    'isRequired' => true,
                ],
                [
                    'segmentCode' => 'series',
                    'segmentName' => 'Series',
                    'segmentTypeCode' => 'variable',
                    'literalValue' => null,
                    'separatorAfter' => null,
                    'isRequired' => true,
                ],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function allProductPermissions(): array
    {
        return [
            'account.login',
            'PRODUCT.READ',
            'PRODUCT.CREATE',
            'PRODUCT.UPDATE',
            'PRODUCT.PUBLISH',
            'PRODUCT.ARCHIVE',
            'PRODUCT.RESTORE',
            'PRODUCT.VARIANTS.MANAGE',
            'PRODUCT.SPECIFICATIONS.MANAGE',
            'PRODUCT.MEDIA.MANAGE',
            'PRODUCT.DOCUMENTS.MANAGE',
            'PRODUCT.RELATIONSHIPS.MANAGE',
            'PRODUCT.CODES.MANAGE',
            'PRODUCT.PRICING.VIEW',
            'PRODUCT.PRICING.MANAGE',
            'PRODUCT.LOOKUPS.MANAGE',
            'PRODUCT.MIGRATION.MANAGE',
            'PRODUCT.AUDIT.VIEW',
        ];
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
    private function stripAdminPermissions(App $app): void
    {
        $repository = $this->userRepository($app);
        $repository->replacePermissions(1, ['account.login'], ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $repository = $this->userRepository($app);
        $repository->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function userRepository(App $app): InMemoryApiUserRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);

        return $repository;
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

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>|null
     */
    private static function findByPublicId(array $items, string $publicId): ?array
    {
        foreach ($items as $item) {
            if (($item['publicId'] ?? null) === $publicId) {
                return $item;
            }
        }

        return null;
    }
}
