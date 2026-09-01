<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Tests\TestAppFactory;

final class PublicProductEndpointsTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    private const REGION_PUBLIC_ID = '22222222-2222-2222-2222-222222222222';

    public function test_public_product_routes_do_not_require_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $slugResponse = $app->handle(TestAppFactory::request('GET', '/api/v1/products/unknown-slug'));
        $slugPayload = TestAppFactory::json($slugResponse);
        self::assertSame(404, $slugResponse->getStatusCode());
        /** @var array<string, mixed> $slugError */
        $slugError = $slugPayload['error'];
        self::assertSame('PRODUCT_NOT_FOUND', $slugError['code']);

        $searchResponse = $app->handle(TestAppFactory::request('GET', '/api/v1/product-search?query=test'));
        self::assertSame(200, $searchResponse->getStatusCode());
    }

    public function test_public_product_by_slug_returns_published_detail(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $slug = $this->createPublishedProductWithSlug($app, 'public-widget', 'Public Widget');

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('Public Widget', $data['productName']);
        self::assertSame($slug, $data['slug']);
        self::assertArrayHasKey('productCode', $data);
        self::assertArrayHasKey('thumbnailUrl', $data);
        self::assertArrayHasKey('thumbnailImageUrl', $data);
        self::assertArrayHasKey('relationships', $data);
        self::assertArrayHasKey('specifications', $data);
        self::assertIsArray($data['variants']);
        self::assertIsArray($data['media']);
        self::assertIsArray($data['documents']);
        self::assertIsArray($data['relationships']);
        self::assertIsArray($data['specifications']);
        self::assertArrayNotHasKey('rowVersion', $data);
        self::assertArrayNotHasKey('erpReference', $data);
        self::assertArrayNotHasKey('legacyProductId', $data);
        self::assertArrayNotHasKey('pricing', $data);
        self::assertArrayNotHasKey('unitPrice', $data);
    }

    public function test_public_customer_view_matches_datasheet_shape_without_auth(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $slug = $this->createPublishedProductWithSlug($app, 'customer-view-widget', 'Customer View Widget');

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . '/customer-view'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('Customer View Widget', $data['productName']);
        self::assertArrayHasKey('variantMatrix', $data);
        self::assertArrayHasKey('columns', $data['variantMatrix']);
        self::assertArrayHasKey('rows', $data['variantMatrix']);
        self::assertArrayHasKey('preview', $data);
        self::assertTrue((bool) $data['preview']['isPublished']);
        self::assertArrayNotHasKey('rowVersion', $data);
        self::assertArrayNotHasKey('pricing', $data);

        $theme = $app->handle(TestAppFactory::request('GET', '/api/v1/customer-view-theme'));
        self::assertSame(200, $theme->getStatusCode());
        $themePayload = TestAppFactory::json($theme);
        self::assertArrayHasKey('theme', $themePayload['data']);
    }

    public function test_public_product_and_customer_view_accept_public_id(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $seeded = $this->seedPublishedProduct($app, 'public-id-widget', 'Public Id Widget');
        $publicId = $seeded['publicId'];

        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $publicId));
        $detailPayload = TestAppFactory::json($detail);
        self::assertSame(200, $detail->getStatusCode(), (string) $detail->getBody());
        /** @var array<string, mixed> $detailData */
        $detailData = $detailPayload['data'];
        self::assertSame('Public Id Widget', $detailData['productName']);
        self::assertSame($publicId, $detailData['publicId']);

        $customerView = $app->handle(
            TestAppFactory::request('GET', '/api/v1/products/' . $publicId . '/customer-view'),
        );
        $customerPayload = TestAppFactory::json($customerView);
        self::assertSame(200, $customerView->getStatusCode(), (string) $customerView->getBody());
        /** @var array<string, mixed> $customerData */
        $customerData = $customerPayload['data'];
        self::assertSame('Public Id Widget', $customerData['productName']);
        self::assertSame($publicId, $customerData['publicId']);
    }

    public function test_public_product_nested_routes_return_items_without_auth(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $slug = $this->createPublishedProductWithSlug($app, 'nested-widget', 'Nested Widget');

        foreach ([
            '/variants',
            '/media',
            '/documents',
            '/relationships',
            '/specifications',
        ] as $suffix) {
            $response = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $slug . $suffix));
            $payload = TestAppFactory::json($response);
            self::assertSame(200, $response->getStatusCode(), $suffix . ' ' . (string) $response->getBody());
            /** @var array<string, mixed> $data */
            $data = $payload['data'];
            self::assertIsArray($data['items']);
            self::assertArrayNotHasKey('rowVersion', $data);
            self::assertArrayNotHasKey('pricing', $data);
        }
    }

    public function test_public_product_uses_region_cookie_when_query_is_absent(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $slug = $this->createPublishedProductWithSlug($app, 'regional-widget', 'Regional Widget');

        $available = $app->handle(
            TestAppFactory::request('GET', '/api/v1/products/' . $slug)
                ->withCookieParams(['tricab_region' => 'AU']),
        );
        self::assertSame(200, $available->getStatusCode());

        $unavailable = $app->handle(
            TestAppFactory::request('GET', '/api/v1/products/' . $slug)
                ->withCookieParams(['tricab_region' => 'US']),
        );
        self::assertSame(404, $unavailable->getStatusCode());
    }

    public function test_public_product_nested_routes_404_for_unknown_slug(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/products/missing-slug/variants'));
        $payload = TestAppFactory::json($response);
        self::assertSame(404, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('PRODUCT_NOT_FOUND', $error['code']);
    }

    public function test_public_product_search_returns_published_matches(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $this->createPublishedProductWithSlug($app, 'searchable-widget', 'Searchable Widget');

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/product-search?query=Searchable'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['items']);
        self::assertGreaterThanOrEqual(1, count($data['items']));
        self::assertArrayHasKey('thumbnailImageUrl', $data['items'][0]);
        self::assertArrayHasKey('thumbnailBackgroundColor', $data['items'][0]);
        self::assertIsArray($data['pagination']);
    }

    public function test_public_product_search_excludes_draft_products(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => 'Draft Only Widget XYZ',
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/product-search?query=Draft%20Only%20Widget%20XYZ'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame([], $data['items']);
        self::assertSame(0, $data['pagination']['totalItems'] ?? null);
    }

    public function test_public_product_search_requires_query(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/product-search'));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('VALIDATION_FAILED', $error['code']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createPublishedProductWithSlug(App $app, string $slug, string $productName): string
    {
        return $this->seedPublishedProduct($app, $slug, $productName)['slug'];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{publicId: string, slug: string}
     */
    private function seedPublishedProduct(App $app, string $slug, string $productName): array
    {
        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $productName,
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $created = TestAppFactory::json($create);
        /** @var array<string, mixed> $createdData */
        $createdData = $created['data'];
        $publicId = (string) $createdData['publicId'];
        $rowVersion = (string) $createdData['rowVersion'];

        $markets = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $publicId . '/markets', [
            'regionPublicIds' => [self::REGION_PUBLIC_ID],
            'marketPublicIds' => [],
        ]));
        self::assertSame(200, $markets->getStatusCode());

        $publish = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $publicId . '/publish', [
            'expectedRowVersion' => $rowVersion,
        ]));
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());

        $slugResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $publicId . '/slugs', [
            'newSlug' => $slug,
        ]));
        self::assertSame(200, $slugResponse->getStatusCode(), (string) $slugResponse->getBody());

        return ['publicId' => $publicId, 'slug' => $slug];
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
