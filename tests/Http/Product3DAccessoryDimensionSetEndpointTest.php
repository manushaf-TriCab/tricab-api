<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\TestAppFactory;

/**
 * accessory-specification-source with template-declared dimension sets.
 */
final class Product3DAccessoryDimensionSetEndpointTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        InMemoryProductBlobStore::resetSharedForTests();
    }

    public function test_sets_are_permitted_missing_or_unsupported(): void
    {
        $app = $this->createApp();
        $product = $this->createProduct($app);
        $base = '/api/v1/admin/products/' . $product . '/3d/accessory-specification-source';

        $group = $this->post($app, '/api/v1/admin/specification-groups', ['name' => 'Dims', 'code' => 'DIMS']);
        $values = [];
        foreach (['OVERALL-LENGTH' => '148', 'THREAD-OD' => '20', 'CABLE-LENGTH' => '3'] as $code => $value) {
            $definition = $this->post($app, '/api/v1/admin/specification-definitions', [
                'name' => $code,
                'code' => $code,
                'dataTypeCode' => 'decimal',
                'scopeCode' => 'product',
                'groupPublicId' => $this->text($group, 'data', 'publicId'),
                'unit' => $code === 'CABLE-LENGTH' ? 'm' : 'mm',
            ]);
            $values[] = ['definitionPublicId' => $this->text($definition, 'data', 'publicId'), 'valueDecimal' => $value];
        }
        $assign = $app->handle($this->json('PUT', '/api/v1/admin/products/' . $product . '/specifications', ['values' => $values]));
        self::assertSame(200, $assign->getStatusCode(), (string) $assign->getBody());

        // Default (no parameter): unchanged behaviour - only the original codes.
        $default = $this->get($app, $base);
        self::assertSame(['OVERALL-LENGTH'], $this->keys($default));

        // Permitted set returns exactly its declared codes that have values (and converts metres).
        $duct = $this->get($app, $base . '?dimensionSet=duct');
        self::assertSame(['CABLE-LENGTH'], $this->keys($duct));
        self::assertSame(3000, (int) $this->number($duct, 'data', 'values', 'CABLE-LENGTH', 'value'));
        self::assertContains('WIDTH', $this->missing($duct));

        $gland = $this->get($app, $base . '?dimensionSet=gland');
        self::assertSame(['THREAD-OD'], $this->keys($gland));

        // Declared but not populated: reported as missing, never invented.
        $lug = $this->get($app, $base . '?dimensionSet=cable-lug');
        self::assertContains('STUD-OD', $this->missing($lug));
        self::assertSame(['OVERALL-LENGTH'], $this->keys($lug));

        // Unsupported set: refused before any lookup.
        $bad = $app->handle(TestAppFactory::request('GET', $base . '?dimensionSet=everything'));
        self::assertSame(422, $bad->getStatusCode(), (string) $bad->getBody());
        $bad404 = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/99999999-9999-9999-9999-999999999999/3d/accessory-specification-source?dimensionSet=everything'));
        self::assertSame(422, $bad404->getStatusCode(), 'validation precedes the product lookup');
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
            'PRODUCT_3D_ENABLED' => 'true',
        ]);
        $store = $app->getContainer()->get(InMemoryProductStore::class);
        self::assertInstanceOf(InMemoryProductStore::class, $store);
        $store->addFamily(['publicId' => self::FAMILY_PUBLIC_ID, 'code' => 'FAM', 'name' => 'Test Family', 'displayOrder' => 1, 'isActive' => true]);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createProduct(App $app): string
    {
        $created = $this->post($app, '/api/v1/admin/products', ['productName' => 'Dimension set product', 'familyPublicId' => self::FAMILY_PUBLIC_ID]);

        return $this->text($created, 'data', 'publicId');
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(App $app, string $path, array $body): array
    {
        $response = $app->handle($this->json('POST', $path, $body));
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getBody());

        return TestAppFactory::json($response);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed>
     */
    private function get(App $app, string $path): array
    {
        $response = $app->handle(TestAppFactory::request('GET', $path));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return TestAppFactory::json($response);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function keys(array $payload): array
    {
        $values = $this->node($payload, 'data', 'values');

        return is_array($values) ? array_map(static fn (int|string $key): string => (string) $key, array_keys($values)) : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function missing(array $payload): array
    {
        $missing = $this->node($payload, 'data', 'missing');

        return is_array($missing) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_values($missing)) : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function number(array $payload, string ...$path): float
    {
        $value = $this->node($payload, ...$path);

        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function text(array $payload, string ...$path): string
    {
        $value = $this->node($payload, ...$path);

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function node(array $payload, string ...$path): mixed
    {
        $value = $payload;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
