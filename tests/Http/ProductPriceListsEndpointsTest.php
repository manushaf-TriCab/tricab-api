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

final class ProductPriceListsEndpointsTest extends TestCase
{
    public function test_create_requires_manage_permission(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'PRODUCT.PRICING.VIEW']);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-price-lists', [
            'name' => 'Blocked',
            'code' => 'BLOCKED',
            'currencyPublicId' => '00000000-0000-0000-0000-000000000099',
        ]));
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_create_list_and_duplicate_legacy_context(): void
    {
        $app = $this->createApp();
        $lookups = TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/admin/product-lookups')))['data'];
        $currencyPublicId = $lookups['currencies'][0]['publicId'] ?? '00000000-0000-0000-0000-000000000001';
        self::assertIsString($currencyPublicId);

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-price-lists', [
            'name' => 'Australia Base Cost',
            'code' => 'TCA-BASE',
            'currencyPublicId' => $currencyPublicId,
            'legacySourceCode' => 'legacy-tc',
            'legacyContextType' => 'dbco',
            'legacyContextCode' => '2507',
            'isActive' => true,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $created = TestAppFactory::json($create)['data'];
        self::assertSame('2507', $created['legacyContextCode']);

        $list = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/product-price-lists'));
        self::assertSame(200, $list->getStatusCode());
        self::assertNotEmpty(TestAppFactory::json($list)['data']['items']);

        $dup = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-price-lists', [
            'name' => 'Duplicate dbco',
            'code' => 'OTHER-BASE',
            'currencyPublicId' => $currencyPublicId,
            'legacySourceCode' => 'legacy-tc',
            'legacyContextType' => 'dbco',
            'legacyContextCode' => '2507',
        ]));
        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('DUPLICATE_LEGACY_PRICE_CONTEXT', TestAppFactory::json($dup)['error']['code']);
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
