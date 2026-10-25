<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;

final class PublicCatalogueNodeAndProductCacheTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    private const REGION_AU = 'a0000000-0000-4000-8000-000000000001';

    private const REGION_US = 'c0000000-0000-4000-8000-000000000003';

    private const BLOB_IMAGE = 'https://tricabv2.blob.core.windows.net/products/catalogue/nodes/control.png';

    public function test_node_is_served_from_cache_with_etag_and_304(): void
    {
        $app = TestAppFactory::create();
        $fixture = $this->seedCatalogue($app);
        $path = '/api/v1/catalogue/nodes/control-cables?regionCode=AU';

        $first = $app->handle(TestAppFactory::request('GET', $path));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertFalse($this->metrics($app)->snapshot()['catalogue_cache_hit']);
        $etag = $first->getHeaderLine('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $etag);
        self::assertStringContainsString('max-age=60', $first->getHeaderLine('Cache-Control'));
        self::assertSame([$fixture['productPublicId']], $this->nodeProductIds($first));

        $second = $app->handle(TestAppFactory::request('GET', $path));
        self::assertSame(200, $second->getStatusCode());
        $snapshot = $this->metrics($app)->snapshot();
        self::assertTrue($snapshot['catalogue_cache_hit']);
        self::assertSame(0, $snapshot['sql_count']);
        self::assertSame(TestAppFactory::json($first), TestAppFactory::json($second));
        self::assertSame($etag, $second->getHeaderLine('ETag'));

        $revalidated = $app->handle(TestAppFactory::request('GET', $path)->withHeader('If-None-Match', $etag));
        self::assertSame(304, $revalidated->getStatusCode());
        self::assertSame('', (string) $revalidated->getBody());
        self::assertSame($etag, $revalidated->getHeaderLine('ETag'));

        // nginx weakens strong ETags when it gzips a response; the weak form must still revalidate.
        $weak = $app->handle(TestAppFactory::request('GET', $path)->withHeader('If-None-Match', 'W/' . $etag));
        self::assertSame(304, $weak->getStatusCode());

        $stale = $app->handle(TestAppFactory::request('GET', $path)->withHeader('If-None-Match', '"stale"'));
        self::assertSame(200, $stale->getStatusCode());

        $byPublicId = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/catalogue/nodes/' . $fixture['nodePublicId'] . '?regionCode=AU',
        ));
        self::assertSame(200, $byPublicId->getStatusCode(), (string) $byPublicId->getBody());
        self::assertSame([$fixture['productPublicId']], $this->nodeProductIds($byPublicId));
    }

    public function test_node_cache_is_separate_per_region(): void
    {
        $app = TestAppFactory::create();
        $this->seedCatalogue($app);

        $au = $app->handle(TestAppFactory::request('GET', '/api/v1/catalogue/nodes/control-cables?regionCode=AU'));
        self::assertSame(200, $au->getStatusCode());

        self::assertNotSame([], $this->nodeProductIds($au));

        $us = $app->handle(TestAppFactory::request('GET', '/api/v1/catalogue/nodes/control-cables?regionCode=US'));
        self::assertFalse($this->metrics($app)->snapshot()['catalogue_cache_hit']);
        self::assertTrue(
            $us->getStatusCode() === 404 || $this->nodeProductIds($us) === [],
            'The AU-only product must not leak into the US node: ' . (string) $us->getBody(),
        );
    }

    public function test_tab_tree_is_served_from_cache_with_etag_and_304(): void
    {
        $app = TestAppFactory::create();
        $this->seedCatalogue($app);
        $path = '/api/v1/catalogue/tabs/products/tree?regionCode=AU';

        $first = $app->handle(TestAppFactory::request('GET', $path));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertFalse($this->metrics($app)->snapshot()['catalogue_cache_hit']);
        $etag = $first->getHeaderLine('ETag');
        self::assertNotSame('', $etag);
        $items = $this->data($first)['items'] ?? null;
        self::assertIsArray($items);
        self::assertNotSame([], $items);

        $second = $app->handle(TestAppFactory::request('GET', $path));
        self::assertTrue($this->metrics($app)->snapshot()['catalogue_cache_hit']);
        self::assertSame(0, $this->metrics($app)->snapshot()['sql_count']);
        self::assertSame(TestAppFactory::json($first), TestAppFactory::json($second));

        $revalidated = $app->handle(TestAppFactory::request('GET', $path)->withHeader('If-None-Match', $etag));
        self::assertSame(304, $revalidated->getStatusCode());

        $withProducts = $app->handle(TestAppFactory::request('GET', $path . '&includeProducts=true'));
        self::assertSame(200, $withProducts->getStatusCode());
        self::assertFalse($this->metrics($app)->snapshot()['catalogue_cache_hit']);
    }

    public function test_product_update_invalidates_cached_catalogue_node(): void
    {
        $app = TestAppFactory::create();
        $fixture = $this->seedCatalogue($app);
        $path = '/api/v1/catalogue/nodes/control-cables?regionCode=AU&tabSlug=products';

        $app->handle(TestAppFactory::request('GET', $path));
        $app->handle(TestAppFactory::request('GET', $path));
        self::assertTrue($this->metrics($app)->snapshot()['catalogue_cache_hit']);

        $update = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/products/' . $fixture['productPublicId'], [
            'expectedRowVersion' => $this->rowVersion($app, $fixture['productPublicId']),
            'productName' => 'Renamed Control Cable',
        ]));
        self::assertSame(200, $update->getStatusCode(), (string) $update->getBody());

        $after = $app->handle(TestAppFactory::request('GET', $path));
        self::assertSame(200, $after->getStatusCode());
        self::assertFalse($this->metrics($app)->snapshot()['catalogue_cache_hit']);
        self::assertSame('Renamed Control Cable', $this->nodeProducts($after)[0]['name'] ?? null);
    }

    public function test_unpublish_drops_product_from_cached_node_and_tree(): void
    {
        $app = TestAppFactory::create();
        $fixture = $this->seedCatalogue($app);
        $nodePath = '/api/v1/catalogue/nodes/control-cables?regionCode=AU';
        $treePath = '/api/v1/catalogue/tabs/products/tree?regionCode=AU&includeProducts=true';

        self::assertSame([$fixture['productPublicId']], $this->nodeProductIds(
            $app->handle(TestAppFactory::request('GET', $nodePath)),
        ));
        $app->handle(TestAppFactory::request('GET', $treePath));

        $unpublish = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/products/' . $fixture['productPublicId'] . '/unpublish',
            ['expectedRowVersion' => $this->rowVersion($app, $fixture['productPublicId'])],
        ));
        self::assertSame(200, $unpublish->getStatusCode(), (string) $unpublish->getBody());

        $node = $app->handle(TestAppFactory::request('GET', $nodePath));
        self::assertFalse($this->metrics($app)->snapshot()['catalogue_cache_hit']);
        self::assertTrue($node->getStatusCode() === 404 || $this->nodeProductIds($node) === []);

        $tree = $app->handle(TestAppFactory::request('GET', $treePath));
        self::assertFalse($this->metrics($app)->snapshot()['catalogue_cache_hit']);
        self::assertStringNotContainsString($fixture['productPublicId'], (string) $tree->getBody());
    }

    public function test_catalogue_navigation_and_region_writes_invalidate_node_cache(): void
    {
        $app = TestAppFactory::create();
        $fixture = $this->seedCatalogue($app);
        $path = '/api/v1/catalogue/nodes/control-cables?regionCode=AU';

        foreach ([
            ['PUT', '/api/v1/admin/products/' . $fixture['productPublicId'] . '/markets', [
                'regionPublicIds' => [self::REGION_AU, self::REGION_US],
                'marketPublicIds' => [],
            ]],
            ['POST', '/api/v1/admin/catalogue-nodes/' . $fixture['nodePublicId'] . '/deactivate', null],
        ] as [$method, $mutation, $body]) {
            $body ??= ['expectedRowVersion' => $this->nodeRowVersion($app, $fixture['nodePublicId'])];
            $app->handle(TestAppFactory::request('GET', $path));
            $app->handle(TestAppFactory::request('GET', $path));
            self::assertTrue($this->metrics($app)->snapshot()['catalogue_cache_hit'], $mutation);

            $response = $app->handle($this->jsonRequest($method, $mutation, $body));
            self::assertLessThan(300, $response->getStatusCode(), $mutation . ' ' . (string) $response->getBody());

            $app->handle(TestAppFactory::request('GET', $path));
            self::assertFalse($this->metrics($app)->snapshot()['catalogue_cache_hit'], $mutation);
        }
    }

    public function test_failed_admin_write_keeps_catalogue_cache(): void
    {
        $app = TestAppFactory::create();
        $fixture = $this->seedCatalogue($app);
        $path = '/api/v1/catalogue/nodes/control-cables?regionCode=AU';
        $app->handle(TestAppFactory::request('GET', $path));

        $rejected = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/products/' . $fixture['productPublicId'], [
            'expectedRowVersion' => 'AAAAAAAAAAA=',
            'productName' => 'Should Not Apply',
        ]));
        self::assertGreaterThanOrEqual(400, $rejected->getStatusCode());

        $app->handle(TestAppFactory::request('GET', $path));
        self::assertTrue($this->metrics($app)->snapshot()['catalogue_cache_hit']);
    }

    public function test_cached_node_gets_a_fresh_image_signature_and_old_etag_stops_matching(): void
    {
        $app = TestAppFactory::create([
            'BLOB_AUTH_MODE' => 'connection-string',
            'BLOB_ACCOUNT_NAME' => 'tricabv2',
            'BLOB_ACCOUNT_KEY' => base64_encode(str_repeat('c', 32)),
            'BLOB_ENDPOINT' => 'https://tricabv2.blob.core.windows.net',
            'BLOB_CONTAINER_PRODUCTS' => 'products',
            'BLOB_CDN_BASE_URL' => '',
            'BLOB_DOWNLOAD_SAS_TTL_SECONDS' => '300',
        ]);
        $this->seedCatalogue($app, self::BLOB_IMAGE);
        $path = '/api/v1/catalogue/nodes/control-cables?regionCode=AU';

        $first = $app->handle(TestAppFactory::request('GET', $path));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $firstImage = $this->stringField($first, 'imageUrl');
        self::assertStringStartsWith(self::BLOB_IMAGE . '?', $firstImage);
        self::assertStringContainsString('sig=', $firstImage);

        sleep(1);
        $second = $app->handle(
            TestAppFactory::request('GET', $path)->withHeader('If-None-Match', $first->getHeaderLine('ETag')),
        );
        self::assertTrue($this->metrics($app)->snapshot()['catalogue_cache_hit']);
        self::assertSame(200, $second->getStatusCode(), 'A 304 would keep the earlier signature alive.');
        $secondImage = $this->stringField($second, 'imageUrl');
        self::assertStringStartsWith(self::BLOB_IMAGE . '?', $secondImage);
        self::assertNotSame($firstImage, $secondImage);
        self::assertNotSame($first->getHeaderLine('ETag'), $second->getHeaderLine('ETag'));
    }

    public function test_customer_view_has_etag_304_and_region_scoped_cache_control(): void
    {
        $app = TestAppFactory::create();
        $fixture = $this->seedCatalogue($app);
        $path = '/api/v1/products/' . $fixture['slug'] . '/customer-view?regionCode=AU';

        $first = $app->handle(TestAppFactory::request('GET', $path));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $etag = $first->getHeaderLine('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $etag);
        self::assertStringStartsWith('public, max-age=60', $first->getHeaderLine('Cache-Control'));

        $revalidated = $app->handle(TestAppFactory::request('GET', $path)->withHeader('If-None-Match', $etag));
        self::assertSame(304, $revalidated->getStatusCode());
        self::assertSame('', (string) $revalidated->getBody());

        $cookieRegion = $app->handle(
            TestAppFactory::request('GET', '/api/v1/products/' . $fixture['slug'] . '/customer-view')
                ->withCookieParams(['tricab_region' => 'AU']),
        );
        self::assertSame(200, $cookieRegion->getStatusCode(), (string) $cookieRegion->getBody());
        self::assertStringStartsWith('private, ', $cookieRegion->getHeaderLine('Cache-Control'));

        $update = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/products/' . $fixture['productPublicId'], [
            'expectedRowVersion' => $this->rowVersion($app, $fixture['productPublicId']),
            'productName' => 'Updated Customer View Name',
        ]));
        self::assertSame(200, $update->getStatusCode(), (string) $update->getBody());

        $afterUpdate = $app->handle(TestAppFactory::request('GET', $path)->withHeader('If-None-Match', $etag));
        self::assertSame(200, $afterUpdate->getStatusCode());
        self::assertNotSame($etag, $afterUpdate->getHeaderLine('ETag'));
        self::assertSame('Updated Customer View Name', $this->stringField($afterUpdate, 'productName'));
    }

    public function test_customer_view_errors_carry_no_etag(): void
    {
        $app = TestAppFactory::create();
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/products/missing-slug/customer-view'));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('ETag'));
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{productPublicId: string, slug: string, nodePublicId: string}
     */
    private function seedCatalogue(App $app, ?string $nodeImageUrl = null): array
    {
        $store = $this->service($app, InMemoryProductStore::class);
        $store->addFamily([
            'publicId' => self::FAMILY_PUBLIC_ID,
            'code' => 'FAM',
            'name' => 'Test Family',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
        foreach ([[self::REGION_AU, 'AU', 'Australia'], [self::REGION_US, 'US', 'United States']] as [$id, $code, $name]) {
            $store->addRegion([
                'publicId' => $id,
                'code' => $code,
                'name' => $name,
                'displayOrder' => 1,
                'isActive' => true,
            ]);
        }

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => 'Control Cable',
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $productPublicId = $this->stringField($create, 'publicId');

        $markets = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $productPublicId . '/markets', [
            'regionPublicIds' => [self::REGION_AU],
            'marketPublicIds' => [],
        ]));
        self::assertSame(200, $markets->getStatusCode(), (string) $markets->getBody());

        $publish = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/publish', [
            'expectedRowVersion' => $this->rowVersion($app, $productPublicId),
        ]));
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());

        $slug = 'control-cable';
        $slugResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $productPublicId . '/slugs', [
            'newSlug' => $slug,
        ]));
        self::assertSame(200, $slugResponse->getStatusCode(), (string) $slugResponse->getBody());

        $repo = $this->service($app, CatalogueNavigationRepository::class);
        $actor = UserId::fromInt(1);
        $tab = $repo->createTab(['tabCode' => 'PRODUCTS', 'tabName' => 'Products', 'slug' => 'products'], $actor);
        $node = $repo->createNode([
            'tabPublicId' => $tab->publicId,
            'nodeCode' => 'CTRL',
            'nodeName' => 'Control Cables',
            'slug' => 'control-cables',
            'imageUrl' => $nodeImageUrl,
        ], $actor);
        $repo->replaceNodeProducts($node->publicId, [
            ['productPublicId' => $productPublicId, 'displayOrder' => 1],
        ], $actor);

        return ['productPublicId' => $productPublicId, 'slug' => $slug, 'nodePublicId' => $node->publicId];
    }

    /**
     * @template T of object
     * @param App<ContainerInterface> $app
     * @param class-string<T> $id
     * @return T
     */
    private function service(App $app, string $id): object
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function metrics(App $app): RequestPerformanceMetrics
    {
        return $this->service($app, RequestPerformanceMetrics::class);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function rowVersion(App $app, string $productPublicId): string
    {
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $productPublicId));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->stringField($response, 'rowVersion');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function nodeRowVersion(App $app, string $nodePublicId): string
    {
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/catalogue-nodes/' . $nodePublicId));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->stringField($response, 'rowVersion');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nodeProducts(ResponseInterface $response): array
    {
        $products = $this->data($response)['products'] ?? [];
        self::assertIsArray($products);
        $list = [];
        foreach ($products as $product) {
            self::assertIsArray($product);
            $list[] = self::stringKeyed($product);
        }

        return $list;
    }

    /**
     * @return list<string>
     */
    private function nodeProductIds(ResponseInterface $response): array
    {
        $ids = [];
        foreach ($this->nodeProducts($response) as $product) {
            self::assertIsString($product['publicId'] ?? null);
            $ids[] = $product['publicId'];
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $data = TestAppFactory::json($response)['data'] ?? null;
        self::assertIsArray($data, (string) $response->getBody());

        return self::stringKeyed($data);
    }

    private function stringField(ResponseInterface $response, string $key): string
    {
        $value = $this->data($response)[$key] ?? null;
        self::assertIsString($value, $key . ': ' . (string) $response->getBody());

        return $value;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $keyed = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keyed[$key] = $item;
            }
        }

        return $keyed;
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
