<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableRoutes;

use PHPUnit\Framework\TestCase;
use TriCab\Api\CableRoutes\Persistence\InMemoryCableRoutesRepository;
use TriCab\Api\CableRoutes\Persistence\SqliteCableRoutesRepository;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Auth\CableRoutesErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesInvalidRequest;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesItemNotFound;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesMachineNotFound;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesStoreUnavailable;
use TriCab\Core\CableRoutes\Application\Query\ChecksFilter;
use TriCab\Core\CableRoutes\Application\Query\ItemsFilter;
use TriCab\Core\CableRoutes\Application\Query\MachineItemsFilter;

final class CableRoutesPersistenceTest extends TestCase
{
    protected function tearDown(): void
    {
        FixtureStore::cleanup();
    }

    public function test_fixture_store_is_built_from_the_engines_schema(): void
    {
        self::assertFileExists(FixtureStore::schemaPath());
        $repository = new SqliteCableRoutesRepository(FixtureStore::create());

        self::assertSame(15, Payload::at($repository->run(), 'counts', 'madeItems'));
    }

    public function test_repository_opens_nothing_until_asked(): void
    {
        $repository = new SqliteCableRoutesRepository(FixtureStore::missingPath());

        $this->expectException(CableRoutesStoreUnavailable::class);
        $repository->run();
    }

    public function test_repository_throws_for_unknown_item_and_machine(): void
    {
        $repository = new SqliteCableRoutesRepository(FixtureStore::create());

        try {
            $repository->item('nope');
            self::fail('Expected CableRoutesItemNotFound.');
        } catch (CableRoutesItemNotFound) {
            // expected
        }
        $this->expectException(CableRoutesMachineNotFound::class);
        $repository->machine('nope', new MachineItemsFilter(null, 1, 50));
    }

    public function test_item_lookup_is_exact(): void
    {
        $repository = new SqliteCableRoutesRepository(FixtureStore::create());

        $this->expectException(CableRoutesItemNotFound::class);
        $repository->item('ka9x-pasn/5c16bk');
    }

    public function test_settings_resolve_a_relative_path_against_the_api_root(): void
    {
        $default = Settings::fromEnvironment([]);
        self::assertSame('var/cable-routes/cable-routes.sqlite', $default->cableRoutesDbPath);
        self::assertSame('var/cable-routes/cable-routes.sqlite', Settings::fromEnvironment(['CABLE_ROUTES_DB_PATH' => '  '])->cableRoutesDbPath);
        self::assertSame('/tmp/x.sqlite', Settings::fromEnvironment(['CABLE_ROUTES_DB_PATH' => '/tmp/x.sqlite'])->cableRoutesDbPath);

        $reflection = new \ReflectionProperty(SqliteCableRoutesRepository::class, 'file');
        $relative = SqliteCableRoutesRepository::fromSettings($default);
        self::assertSame(dirname(__DIR__, 2) . '/var/cable-routes/cable-routes.sqlite', $reflection->getValue($relative));

        $absolute = SqliteCableRoutesRepository::fromSettings(Settings::fromEnvironment(['CABLE_ROUTES_DB_PATH' => '/tmp/x.sqlite']));
        self::assertSame('/tmp/x.sqlite', $reflection->getValue($absolute));
    }

    public function test_in_memory_repository_serves_canned_payloads(): void
    {
        $repository = new InMemoryCableRoutesRepository(
            run: ['run' => []],
            itemsByNumber: ['A/1C1' => ['item' => []]],
            machinesById: ['EX6' => ['machine' => []]],
        );

        self::assertSame(['run' => []], $repository->run());
        self::assertSame(['item' => []], $repository->item('A/1C1'));
        self::assertSame(['machine' => []], $repository->machine('EX6', new MachineItemsFilter(null, 1, 50)));
        self::assertSame([], $repository->items(new ItemsFilter(null, null, null, null, 1, 50)));
        self::assertSame([], $repository->checks(new ChecksFilter(null, null, 1, 100)));
        self::assertSame(['run', 'item', 'machine', 'items', 'checks'], $repository->calls);

        $this->expectException(CableRoutesItemNotFound::class);
        $repository->item('missing');
    }

    public function test_in_memory_repository_can_be_unavailable(): void
    {
        $repository = new InMemoryCableRoutesRepository(available: false);

        $this->expectException(CableRoutesStoreUnavailable::class);
        $repository->codes();
    }

    public function test_error_mapper(): void
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        $responder = $container->get(JsonResponder::class);
        self::assertInstanceOf(JsonResponder::class, $responder);
        $mapper = new CableRoutesErrorMapper($responder);

        $cases = [
            [new CableRoutesInvalidRequest('CABLE_ROUTES_KIND_INVALID', 'kind is not a known check kind.'), 422, 'CABLE_ROUTES_KIND_INVALID'],
            [new CableRoutesItemNotFound('x'), 404, 'CABLE_ROUTES_ITEM_NOT_FOUND'],
            [new CableRoutesMachineNotFound('x'), 404, 'CABLE_ROUTES_MACHINE_NOT_FOUND'],
            [new CableRoutesStoreUnavailable('/secret/path/cable.sqlite is missing'), 503, 'CABLE_ROUTES_STORE_UNAVAILABLE'],
            [new \RuntimeException('/secret/path boom'), 503, 'CABLE_ROUTES_STORE_UNAVAILABLE'],
        ];
        $log = ini_set('error_log', '/dev/null');
        try {
            foreach ($cases as [$exception, $status, $code]) {
                $response = $mapper->map($exception);
                $body = TestAppFactory::json($response);

                self::assertSame($status, $response->getStatusCode());
                self::assertFalse($body['success']);
                self::assertSame($code, Payload::obj($body['error'])['code']);
                self::assertStringNotContainsString('secret', Payload::str(Payload::obj($body['error'])['message']));
            }
        } finally {
            if ($log !== false) {
                ini_set('error_log', $log);
            }
        }
    }
}
