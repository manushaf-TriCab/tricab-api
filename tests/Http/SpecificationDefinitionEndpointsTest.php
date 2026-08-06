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

final class SpecificationDefinitionEndpointsTest extends TestCase
{
    public function test_create_group_definition_and_duplicate_code(): void
    {
        $app = TestAppFactory::create([]);

        $groupResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-groups', [
            'name' => 'Electrical',
            'code' => 'electrical',
            'displayOrder' => 10,
        ]));
        self::assertSame(201, $groupResponse->getStatusCode(), (string) $groupResponse->getBody());
        $group = TestAppFactory::json($groupResponse)['data'];
        self::assertSame('ELECTRICAL', $group['code']);

        $defResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-definitions', [
            'name' => 'Voltage',
            'code' => 'voltage',
            'dataTypeCode' => 'measurement',
            'scopeCode' => 'product',
            'groupPublicId' => $group['publicId'],
            'unit' => 'V',
            'validationRules' => ['helpText' => 'Nominal'],
        ]));
        self::assertSame(201, $defResponse->getStatusCode(), (string) $defResponse->getBody());
        $def = TestAppFactory::json($defResponse)['data'];
        self::assertSame('VOLTAGE', $def['code']);
        self::assertSame('Nominal', $def['validationRules']['helpText']);

        $dup = $app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-definitions', [
            'name' => 'Other',
            'code' => 'VOLTAGE',
            'dataTypeCode' => 'text',
        ]));
        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('DUPLICATE_SPECIFICATION_CODE', TestAppFactory::json($dup)['error']['code']);
    }

    public function test_manage_requires_permission(): void
    {
        $app = TestAppFactory::create([]);
        $this->replacePermissions($app, ['account.login', 'PRODUCT.READ']);
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-units', [
            'name' => 'Volt',
            'code' => 'V',
            'symbol' => 'V',
        ]));
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_import_export_and_unit_create(): void
    {
        $app = TestAppFactory::create([]);
        $app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-groups', [
            'name' => 'Electrical',
            'code' => 'ELECTRICAL',
        ]));
        $unitResponse = $app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-units', [
            'name' => 'Volt',
            'code' => 'V',
            'symbol' => 'V',
        ]));
        self::assertSame(201, $unitResponse->getStatusCode(), (string) $unitResponse->getBody());
        $unit = TestAppFactory::json($unitResponse)['data'];
        self::assertSame('V', $unit['symbol']);

        $import = $app->handle($this->jsonRequest('POST', '/api/v1/admin/specification-definitions/import', [
            'definitions' => [[
                'code' => 'CURRENT',
                'name' => 'Current',
                'groupCode' => 'ELECTRICAL',
                'dataTypeCode' => 'measurement',
                'scopeCode' => 'both',
                'unit' => 'A',
            ]],
        ]));
        self::assertSame(200, $import->getStatusCode(), (string) $import->getBody());
        $summary = TestAppFactory::json($import)['data'];
        self::assertSame(1, $summary['created']);

        $export = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/specification-definitions/export'));
        self::assertSame(200, $export->getStatusCode());
        $definitions = TestAppFactory::json($export)['data']['definitions'];
        self::assertNotEmpty($definitions);
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
