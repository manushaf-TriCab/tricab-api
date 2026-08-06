<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

final class ProductClassificationEndpointsTest extends TestCase
{
    public function test_create_list_and_duplicate_family(): void
    {
        $app = $this->createApp();

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-families', [
            'name' => 'Industrial Cables',
            'code' => 'industrial',
            'description' => 'Industrial',
            'displayOrder' => 10,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $created = TestAppFactory::json($create)['data'];
        self::assertSame('INDUSTRIAL', $created['code']);
        self::assertArrayNotHasKey('id', $created);
        self::assertIsString($created['rowVersion']);

        $list = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/product-families'));
        self::assertSame(200, $list->getStatusCode());
        $items = TestAppFactory::json($list)['data']['items'];
        self::assertNotEmpty($items);

        $dup = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-families', [
            'name' => 'Other',
            'code' => 'INDUSTRIAL',
        ]));
        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('DUPLICATE_PRODUCT_CLASSIFICATION_CODE', TestAppFactory::json($dup)['error']['code']);
    }

    public function test_category_requires_manage_permission(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'PRODUCT.READ']);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-categories', [
            'familyPublicId' => '00000000-0000-0000-0000-000000000001',
            'name' => 'Flex',
            'code' => 'FLEX',
        ]));
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_family_category_series_hierarchy_and_usage(): void
    {
        $app = $this->createApp();
        $family = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/product-families', [
            'name' => 'Mining',
            'code' => 'MINING',
        ])))['data'];

        $category = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/product-categories', [
            'familyPublicId' => $family['publicId'],
            'name' => 'Trailing',
            'code' => 'TRAIL',
        ])))['data'];
        self::assertSame($family['publicId'], $category['parentPublicId']);

        $series = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/product-series', [
            'categoryPublicId' => $category['publicId'],
            'name' => 'Tuff',
            'code' => 'TUFF',
        ])))['data'];
        self::assertSame($category['publicId'], $series['parentPublicId']);

        $usage = TestAppFactory::json($app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/product-categories/' . $category['publicId'] . '/usage',
        )))['data'];
        self::assertSame(1, $usage['activeSeriesCount']);

        $blocked = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-categories/' . $category['publicId'] . '/deactivate',
            ['expectedRowVersion' => $category['rowVersion']],
        ));
        self::assertSame(422, $blocked->getStatusCode());
        self::assertSame('PRODUCT_CLASSIFICATION_IN_USE', TestAppFactory::json($blocked)['error']['code']);
    }

    /** @return App<ContainerInterface> */
    private function createApp(): App
    {
        return TestAppFactory::create([]);
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
}
