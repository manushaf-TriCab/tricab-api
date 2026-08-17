<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Tests\TestAppFactory;

final class RegionEndpointsTest extends TestCase
{
    public function test_public_regions_list_returns_active_picker_regions(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/regions'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['items']);
        $codes = array_map(
            static fn (array $item): string => (string) $item['code'],
            $data['items'],
        );
        self::assertContains('AU', $codes);
        self::assertContains('NZ', $codes);
        self::assertContains('US', $codes);
        self::assertContains('AS', $codes);
    }

    public function test_current_region_defaults_to_au_without_cookie(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/regions/current'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('default', $data['source']);
        /** @var array<string, mixed> $region */
        $region = $data['region'];
        self::assertSame('AU', $region['code']);
    }

    public function test_select_region_sets_cookie_and_returns_selected_region(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/regions/select', ['region' => 'NZ']));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('tricab_region=NZ', $response->getHeaderLine('Set-Cookie'));
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('cookie', $data['source']);
        /** @var array<string, mixed> $region */
        $region = $data['region'];
        self::assertSame('NZ', $region['code']);
    }

    public function test_select_invalid_region_returns_validation_error(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/regions/select', ['region' => 'ZZ']));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INVALID_REGION', $error['code']);
    }

    public function test_admin_regions_require_permission(): void
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertNotNull($container);
        $users = $container->get(\TriCab\Core\Identity\Contract\UserRepository::class);
        self::assertInstanceOf(\TriCab\Api\Identity\Persistence\InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/regions'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_admin_region_crud_lifecycle(): void
    {
        $app = TestAppFactory::create();

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/regions', [
            'code' => 'EU',
            'name' => 'Europe',
            'displayName' => 'Europe',
            'flag' => '🇪🇺',
            'displayOrder' => 50,
            'isDefault' => false,
            'isActive' => true,
            'websiteEnabled' => true,
            'showInRegionPicker' => true,
            'defaultWebsiteRegion' => false,
            'countryCodes' => ['DE', 'FR'],
        ]));
        $created = TestAppFactory::json($create);
        self::assertSame(201, $create->getStatusCode());
        /** @var array<string, mixed> $region */
        $region = $created['data'];
        self::assertSame('EU', $region['code']);
        self::assertSame(2, $region['countryCount']);
        self::assertArrayHasKey('productCount', $region);

        $publicId = (string) $region['publicId'];
        $rowVersion = (string) $region['rowVersion'];

        $update = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/regions/' . $publicId, [
            'code' => 'EU',
            'name' => 'Europe',
            'displayName' => 'Europe & UK',
            'flag' => '🇪🇺',
            'displayOrder' => 55,
            'isDefault' => false,
            'isActive' => true,
            'websiteEnabled' => true,
            'showInRegionPicker' => true,
            'defaultWebsiteRegion' => false,
            'countryCodes' => ['DE', 'FR', 'GB'],
            'expectedRowVersion' => $rowVersion,
        ]));
        $updated = TestAppFactory::json($update);
        self::assertSame(200, $update->getStatusCode());
        /** @var array<string, mixed> $updatedRegion */
        $updatedRegion = $updated['data'];
        self::assertSame('Europe & UK', $updatedRegion['displayName']);

        $deactivate = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/regions/' . $publicId . '/deactivate',
            ['expectedRowVersion' => $updatedRegion['rowVersion']],
        ));
        self::assertSame(200, $deactivate->getStatusCode());

        $delete = $app->handle($this->jsonRequest(
            'DELETE',
            '/api/v1/admin/regions/' . $publicId,
            ['expectedRowVersion' => TestAppFactory::json($deactivate)['data']['rowVersion']],
        ));
        self::assertSame(200, $delete->getStatusCode());
    }

    public function test_admin_cannot_delete_region_in_use(): void
    {
        $app = TestAppFactory::create();
        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/regions', [
            'code' => 'CA',
            'name' => 'Canada',
            'countryCodes' => ['CA'],
        ]));
        /** @var array<string, mixed> $region */
        $region = TestAppFactory::json($create)['data'];

        $container = $app->getContainer();
        self::assertNotNull($container);
        $store = $container->get(\TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore::class);
        self::assertInstanceOf(\TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore::class, $store);
        $store->setProductRegions('used-product', [(string) $region['publicId']]);

        $delete = $app->handle($this->jsonRequest(
            'DELETE',
            '/api/v1/admin/regions/' . $region['publicId'],
            ['expectedRowVersion' => $region['rowVersion']],
        ));
        self::assertSame(422, $delete->getStatusCode());
        self::assertSame('REGION_IN_USE', TestAppFactory::json($delete)['error']['code']);
    }

    public function test_region_cookie_cors_allows_credentials_for_configured_origin_only(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $allowed = $app->handle(
            TestAppFactory::request('OPTIONS', '/api/v1/regions/select')
                ->withHeader('Origin', 'http://localhost:5173'),
        );
        self::assertSame('http://localhost:5173', $allowed->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $allowed->getHeaderLine('Access-Control-Allow-Credentials'));

        $blocked = $app->handle(
            TestAppFactory::request('OPTIONS', '/api/v1/regions/select')
                ->withHeader('Origin', 'https://untrusted.example'),
        );
        self::assertSame('', $blocked->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $blocked->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    private function jsonRequest(string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');

        if ($body !== []) {
            $request->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }

        return $request;
    }
}
