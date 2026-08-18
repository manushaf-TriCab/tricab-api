<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Tests\TestAppFactory;

final class ProductRegionEndpointsTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    private const REGION_AU = 'a0000000-0000-4000-8000-000000000001';

    private const REGION_NZ = 'b0000000-0000-4000-8000-000000000002';

    private const REGION_US = 'c0000000-0000-4000-8000-000000000003';

    public function test_get_product_regions_returns_active_regions_with_selection(): void
    {
        $app = TestAppFactory::create();
        $this->seedFamily($app);
        $productPublicId = $this->createDraftProduct($app);

        $assign = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => [self::REGION_AU, self::REGION_NZ],
        ]));
        self::assertSame(200, $assign->getStatusCode(), (string) $assign->getBody());

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId . '/regions',
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['availableRegions']);
        self::assertGreaterThanOrEqual(4, count($data['availableRegions']));

        $selected = array_values(array_filter(
            $data['availableRegions'],
            static fn (array $item): bool => ($item['selected'] ?? false) === true,
        ));
        $selectedCodes = array_map(static fn (array $item): string => (string) $item['code'], $selected);
        self::assertContains('AU', $selectedCodes);
        self::assertContains('NZ', $selectedCodes);
        self::assertNotContains('US', $selectedCodes);
    }

    public function test_put_product_regions_replaces_assignments(): void
    {
        $app = TestAppFactory::create();
        $this->seedFamily($app);
        $productPublicId = $this->createDraftProduct($app);

        self::assertSame(200, $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => [self::REGION_AU],
        ]))->getStatusCode());

        self::assertSame(200, $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => [self::REGION_AU, self::REGION_US],
        ]))->getStatusCode());

        $detail = TestAppFactory::json($app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId,
        )));
        /** @var array<string, mixed> $detailData */
        $detailData = $detail['data'];
        self::assertSame(['AU', 'US'], $detailData['regionCodes']);
    }

    public function test_put_product_regions_allows_clear_for_draft(): void
    {
        $app = TestAppFactory::create();
        $this->seedFamily($app);
        $productPublicId = $this->createDraftProduct($app);

        self::assertSame(200, $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => [self::REGION_AU],
        ]))->getStatusCode());

        self::assertSame(200, $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => [],
        ]))->getStatusCode());
    }

    public function test_put_product_regions_rejects_unknown_region(): void
    {
        $app = TestAppFactory::create();
        $this->seedFamily($app);
        $productPublicId = $this->createDraftProduct($app);

        $response = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => ['00000000-0000-4000-8000-000000000099'],
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_put_product_regions_rejects_duplicate_region_ids(): void
    {
        $app = TestAppFactory::create();
        $this->seedFamily($app);
        $productPublicId = $this->createDraftProduct($app);

        $response = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => [self::REGION_AU, self::REGION_AU],
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_put_product_regions_rejects_clear_for_published_product(): void
    {
        $app = TestAppFactory::create();
        $this->seedFamily($app);
        $productPublicId = $this->createDraftProduct($app);

        self::assertSame(200, $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => [self::REGION_AU],
        ]))->getStatusCode());

        $detail = TestAppFactory::json($app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId,
        )));
        /** @var array<string, mixed> $detailData */
        $detailData = $detail['data'];

        self::assertSame(200, $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/publish', [
            'expectedRowVersion' => (string) $detailData['rowVersion'],
        ]))->getStatusCode());

        $response = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => [],
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_public_product_visibility_follows_region_assignment(): void
    {
        $app = TestAppFactory::create();
        $this->seedFamily($app);
        $productPublicId = $this->createDraftProduct($app, 'Regional Product');

        self::assertSame(200, $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/regions', [
            'regionPublicIds' => [self::REGION_AU, self::REGION_NZ],
        ]))->getStatusCode());

        $detail = TestAppFactory::json($app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $productPublicId,
        )));
        /** @var array<string, mixed> $detailData */
        $detailData = $detail['data'];
        self::assertSame(200, $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/publish', [
            'expectedRowVersion' => (string) $detailData['rowVersion'],
        ]))->getStatusCode());

        self::assertSame(200, $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/slugs', [
            'newSlug' => 'regional-product',
        ]))->getStatusCode());

        $available = $app->handle(
            TestAppFactory::request('GET', '/api/v1/products/regional-product')
                ->withCookieParams(['tricab_region' => 'AU']),
        );
        self::assertSame(200, $available->getStatusCode());

        $hidden = $app->handle(
            TestAppFactory::request('GET', '/api/v1/products/regional-product')
                ->withCookieParams(['tricab_region' => 'US']),
        );
        self::assertSame(404, $hidden->getStatusCode());
    }

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    private function seedFamily(App $app): void
    {
        $container = $app->getContainer();
        self::assertNotNull($container);
        /** @var \TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore $store */
        $store = $container->get(\TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore::class);
        $store->addFamily([
            'publicId' => self::FAMILY_PUBLIC_ID,
            'code' => 'FAM',
            'name' => 'Test Family',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
    }

    /**
     * @param App<\Psr\Container\ContainerInterface> $app
     */
    private function createDraftProduct(App $app, string $productName = 'Region Test Product'): string
    {
        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $productName,
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $created = TestAppFactory::json($create);
        /** @var array<string, mixed> $createdData */
        $createdData = $created['data'];

        return (string) $createdData['publicId'];
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
}
