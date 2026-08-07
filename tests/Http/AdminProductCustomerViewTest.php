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

final class AdminProductCustomerViewTest extends TestCase
{
    private const NONEXISTENT_PUBLIC_ID = '44444444-4444-4444-8444-444444444444';

    public function test_customer_view_requires_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . self::NONEXISTENT_PUBLIC_ID . '/customer-view',
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('UNAUTHENTICATED', $error['code']);
    }

    public function test_customer_view_requires_permission(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createProduct($app, 'No Permission Product');
        $this->stripAdminPermissions($app);

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $publicId . '/customer-view',
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_draft_product_customer_view_returns_200(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createProduct($app, 'Draft Customer View Product');

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . $publicId . '/customer-view',
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        self::assertSame($publicId, $data['publicId']);
        self::assertSame('Draft Customer View Product', $data['productName']);
        self::assertIsArray($data['preview']);
        /** @var array<string, mixed> $preview */
        $preview = $data['preview'];
        self::assertFalse($preview['isPublished']);
        self::assertSame('draft', $preview['statusCode']);
        self::assertFalse($preview['isArchived']);
        self::assertArrayHasKey('lifecycleStatusCode', $preview);
        self::assertArrayHasKey('primarySlug', $preview);

        self::assertIsArray($data['variantMatrix']);
        /** @var array<string, mixed> $matrix */
        $matrix = $data['variantMatrix'];
        self::assertArrayHasKey('columns', $matrix);
        self::assertArrayHasKey('rows', $matrix);
        self::assertIsArray($matrix['columns']);
        self::assertIsArray($matrix['rows']);

        self::assertArrayHasKey('variants', $data);
        self::assertArrayHasKey('media', $data);
        self::assertArrayHasKey('documents', $data);
        self::assertArrayHasKey('specifications', $data);
        self::assertArrayHasKey('relationships', $data);
        self::assertArrayNotHasKey('rowVersion', $data);
        self::assertArrayNotHasKey('pricing', $data);
    }

    public function test_customer_view_unknown_product_returns_404(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/admin/products/' . self::NONEXISTENT_PUBLIC_ID . '/customer-view',
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(404, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('PRODUCT_NOT_FOUND', $error['code']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createProduct(App $app, string $productName): string
    {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $productName,
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
    private function stripAdminPermissions(App $app): void
    {
        $repository = $this->userRepository($app);
        $repository->replacePermissions(1, ['account.login'], ['EMPLOYEE']);
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
}
