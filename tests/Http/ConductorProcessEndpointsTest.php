<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Conductor\Process\FixtureStore;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Conductor\Module;
use TriCab\Core\Identity\Contract\UserRepository;

final class ConductorProcessEndpointsTest extends TestCase
{
    private const BASE = '/api/v1/conductor/process/';

    protected function tearDown(): void
    {
        FixtureStore::cleanup();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endpoints(): iterable
    {
        foreach (['run', 'machines', 'machine?machine=BU6', 'items', 'item?item=NX-1C16'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('endpoints')]
    public function test_unauthenticated_is_rejected(string $path): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false', 'CONDUCTOR_PROCESS_DB_PATH' => FixtureStore::create()]);
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    #[DataProvider('endpoints')]
    public function test_without_the_permission_is_forbidden(string $path): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_PROCESS_DB_PATH' => FixtureStore::create()]);
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', 'CABLE_ROUTES.READ']);
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('INSUFFICIENT_PERMISSION', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    #[DataProvider('endpoints')]
    public function test_with_only_the_permission_is_allowed(string $path): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_PROCESS_DB_PATH' => FixtureStore::create()]);
        $this->replacePermissions($app, [Module::PERMISSION_READ]);

        $this->get($app, $path);
    }

    #[DataProvider('endpoints')]
    public function test_missing_store_is_503_without_a_path(string $path): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_PROCESS_DB_PATH' => FixtureStore::missingPath()]);

        [$status, $error] = $this->getError($app, $path);

        self::assertSame(503, $status);
        self::assertSame('CONDUCTOR_STORE_UNAVAILABLE', $error['code']);
        self::assertStringNotContainsString('/', Payload::str($error['message']), 'the message never contains a path');
    }

    public function test_run(): void
    {
        self::assertSame(
            [
                'run' => ['builtAt' => '2026-10-28T01:02:03Z', 'dataAsOf' => '2026-10-27', 'windowFrom' => '2025-10-28', 'sourceName' => 'snapshot-2026-10-28', 'schemaVersion' => 1, 'engineVersion' => '1.0.0'],
                'counts' => ['items' => 9, 'finishedItems' => 4, 'tracedToRod' => 8, 'excludedOffMap' => 2],
                'stages' => [['stage' => 'wire', 'items' => 4], ['stage' => 'bunch', 'items' => 1], ['stage' => 'conductor', 'items' => 3], ['stage' => 'mica_taped', 'items' => 1]],
            ],
            $this->get($this->app(), 'run'),
        );
    }

    public function test_run_without_a_run_row_is_null(): void
    {
        $data = $this->get(TestAppFactory::create(['CONDUCTOR_PROCESS_DB_PATH' => FixtureStore::createWithoutRun()]), 'run');

        self::assertNull($data['run']);
        self::assertSame(['items' => 0, 'finishedItems' => 0, 'tracedToRod' => 0, 'excludedOffMap' => 0], $data['counts']);
    }

    public function test_machines(): void
    {
        $machines = Payload::objList($this->get($this->app(), 'machines')['machines']);

        self::assertCount(15, $machines);
        self::assertSame(['RB2', 'WD3', 'WD4', 'TN1', 'WD5', 'BU1', 'BU2', 'BU6', 'BU7', 'BU5', 'BU4', 'TP3', 'TP1', 'TP2', 'ST1'], array_column($machines, 'machine'));
        self::assertSame(
            [
                'machine' => 'RB2', 'd365Id' => 'RB1', 'seq' => 1, 'stageNo' => 1, 'stage' => 'Rod breaking', 'posterStatus' => 'existing',
                'brand' => 'NIEHOFF', 'machineType' => 'Rod breaker', 'inputLimit' => '8 mm soft Cu rod', 'outputCapability' => '1.2 to 2.5 mm wire',
                'payOff' => null, 'takeUp' => null, 'speedText' => '29 m/s', 'note' => 'D365 calls it RB1', 'items' => 1,
                'ordersTotal' => 480, 'ordersCancelled' => 7, 'orders52w' => 300, 'cancelledShare' => 7 / 480,
            ],
            $machines[0],
        );
        self::assertSame('91% of D365 jobs cancelled', $machines[8]['note']);
        self::assertEqualsWithDelta(10 / 11, $machines[8]['cancelledShare'], 1e-12);
        self::assertNull($machines[11]['d365Id']);
        self::assertNull($machines[11]['cancelledShare']);
    }

    public function test_machine_by_d365_id(): void
    {
        $data = $this->get($this->app(), 'machine?machine=tl1');

        self::assertSame('TN1', Payload::obj($data['machine'])['machine']);
        self::assertSame('TL1', Payload::obj($data['machine'])['d365Id']);
        self::assertSame(
            [['operation' => 'ANNEAL', 'orders' => 56], ['operation' => 'TINNING', 'orders' => 56], ['operation' => 'WIRE_DRAW', 'orders' => 2]],
            $data['operations'],
        );
        self::assertSame(
            [['item' => 'DX-1W.5', 'stage' => 'wire', 'material' => 'tinned', 'size' => 0.5, 'form' => 'wires', 'role' => 'representative', 'orders' => 58]],
            $data['items'],
        );
        self::assertSame('RB2', Payload::obj($this->get($this->app(), 'machine?machine=RB1')['machine'])['machine']);
    }

    public function test_machine_with_representative_and_alternative_items(): void
    {
        $data = $this->get($this->app(), 'machine?machine=BU2');

        self::assertSame([], $data['operations']);
        self::assertSame(
            [
                ['item' => 'DX-1C2.5', 'stage' => 'conductor', 'material' => 'tinned', 'size' => 2.5, 'form' => 'core', 'role' => 'representative', 'orders' => 80],
                ['item' => 'NX-1C16', 'stage' => 'conductor', 'material' => 'plain', 'size' => 16, 'form' => 'core', 'role' => 'alternative', 'orders' => 11],
            ],
            $data['items'],
        );
    }

    public function test_machine_required_and_unknown(): void
    {
        $app = $this->app();
        foreach (['machine', 'machine?machine=', 'machine?machine=%20'] as $path) {
            [$status, $error] = $this->getError($app, $path);
            self::assertSame(422, $status);
            self::assertSame('CONDUCTOR_MACHINE_REQUIRED', $error['code']);
        }

        foreach (['EX6', 'LU3'] as $off) {
            [$status, $error] = $this->getError($app, 'machine?machine=' . $off);
            self::assertSame(404, $status);
            self::assertSame('CONDUCTOR_MACHINE_NOT_FOUND', $error['code']);
        }
    }

    public function test_items(): void
    {
        $data = $this->get($this->app(), 'items?stage=finished&pageSize=2&page=2');

        self::assertSame(
            [
                'items' => [
                    ['item' => 'VX-1C35', 'pool' => '25_FCON', 'stage' => 'conductor', 'prefix' => 'VX', 'material' => 'unknown', 'mica' => false, 'form' => 'core', 'ends' => 1, 'size' => 35, 'kgPerM' => 0.3, 'unit' => 'm', 'orders52w' => 3, 'runSize' => 500, 'tracesToRod' => false],
                    ['item' => 'NM-1C16', 'pool' => '30_TAPE', 'stage' => 'mica_taped', 'prefix' => 'NM', 'material' => 'plain', 'mica' => true, 'form' => 'core', 'ends' => 1, 'size' => 16, 'kgPerM' => 0.15, 'unit' => 'm', 'orders52w' => 20, 'runSize' => 1000, 'tracesToRod' => true],
                ],
                'page' => 2,
                'pageSize' => 2,
                'totalItems' => 4,
                'totalPages' => 2,
            ],
            $data,
        );

        $all = $this->get($this->app(), 'items');
        self::assertSame(
            ['NX-16W.3', 'DX-1W.5', 'NX-1W1.6', 'NX-WEIRD', 'NX-32W.3', 'DX-1C2.5', 'NX-1C16', 'VX-1C35', 'NM-1C16'],
            array_column(Payload::objList($all['items']), 'item'),
        );
        self::assertSame([1, 50, 9, 1], [$all['page'], $all['pageSize'], $all['totalItems'], $all['totalPages']]);

        $search = $this->get($this->app(), 'items?q=' . rawurlencode('dx-1') . '&material=tinned');
        self::assertSame(['DX-1W.5', 'DX-1C2.5'], array_column(Payload::objList($search['items']), 'item'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badParameters(): iterable
    {
        yield 'unknown stage' => ['items?stage=cable'];
        yield 'unknown material' => ['items?material=aluminium'];
        yield 'page zero' => ['items?page=0'];
        yield 'pageSize too large' => ['items?pageSize=201'];
        yield 'pageSize zero' => ['items?pageSize=0'];
        yield 'length zero' => ['item?item=NX-1C16&length=0'];
        yield 'length negative' => ['item?item=NX-1C16&length=-5'];
        yield 'length text' => ['item?item=NX-1C16&length=lots'];
        yield 'length too large' => ['item?item=NX-1C16&length=10000001'];
    }

    #[DataProvider('badParameters')]
    public function test_bad_parameter_is_422(string $path): void
    {
        [$status, $error] = $this->getError($this->app(), $path);

        self::assertSame(422, $status);
        self::assertSame('CONDUCTOR_PARAM_INVALID', $error['code']);
    }

    public function test_item(): void
    {
        $data = $this->get($this->app(), 'item?item=dx-1c2.5&length=100');

        self::assertSame(
            [
                'item' => [
                    'item' => 'DX-1C2.5', 'pool' => '25_FCON', 'stage' => 'conductor', 'prefix' => 'DX', 'material' => 'tinned', 'mica' => false,
                    'form' => 'core', 'ends' => 1, 'size' => 2.5, 'kgPerM' => 0.0225, 'unit' => 'm', 'orders52w' => 60, 'runSize' => 3000,
                    'tracesToRod' => true, 'diameterMm' => 2.75, 'orderNo' => 'W0049506', 'orderStatus' => 'Released', 'orderDate' => '2026-10-26',
                    'realOrders' => 80, 'liveOrders' => 80, 'stepCount' => 3,
                ],
                'length' => 100,
                'steps' => [
                    [
                        'stepNo' => 1, 'item' => 'NX-1W1.6', 'stage' => 'wire', 'material' => 'plain', 'form' => 'wires', 'ends' => 1, 'size' => 1.6,
                        'unit' => 'm', 'kgPerM' => 0.01804, 'depth' => 2, 'quantity' => 130, 'kg' => 2.3452,
                        'operations' => [['seq' => 1, 'operation' => 'ROD_BREAK', 'machine' => 'RB2', 'd365Id' => 'RB1', 'stageNo' => 1, 'alternatives' => []]],
                        'inputs' => [['item' => 'NX-1R8-NEX', 'kind' => 'material', 'qtyPerUnit' => 0.01804, 'unit' => 'kg', 'parallelEnds' => 1, 'quantity' => 2.3452]],
                    ],
                    [
                        'stepNo' => 2, 'item' => 'DX-1W.5', 'stage' => 'wire', 'material' => 'tinned', 'form' => 'wires', 'ends' => 1, 'size' => 0.5,
                        'unit' => 'm', 'kgPerM' => 0.0018, 'depth' => 1, 'quantity' => 1300, 'kg' => 2.34,
                        'operations' => [
                            ['seq' => 1, 'operation' => 'WIRE_DRAW', 'machine' => 'WD5', 'd365Id' => 'WD5', 'stageNo' => 2, 'alternatives' => []],
                            ['seq' => 2, 'operation' => 'TINNING', 'machine' => 'TN1', 'd365Id' => 'TL1', 'stageNo' => 2, 'alternatives' => []],
                        ],
                        'inputs' => [
                            ['item' => 'NX-1W1.6', 'kind' => 'made', 'qtyPerUnit' => 0.1, 'unit' => 'm', 'parallelEnds' => 1, 'quantity' => 130],
                            ['item' => 'TIN_ANODE', 'kind' => 'material', 'qtyPerUnit' => 0.0001, 'unit' => 'kg', 'parallelEnds' => 1, 'quantity' => 0.13],
                        ],
                    ],
                    [
                        'stepNo' => 3, 'item' => 'DX-1C2.5', 'stage' => 'conductor', 'material' => 'tinned', 'form' => 'core', 'ends' => 1, 'size' => 2.5,
                        'unit' => 'm', 'kgPerM' => 0.0225, 'depth' => 0, 'quantity' => 100, 'kg' => 2.25,
                        'operations' => [['seq' => 1, 'operation' => 'BUNCHING', 'machine' => 'BU2', 'd365Id' => 'BU2', 'stageNo' => 3, 'alternatives' => []]],
                        'inputs' => [['item' => 'DX-1W.5', 'kind' => 'made', 'qtyPerUnit' => 13, 'unit' => 'm', 'parallelEnds' => 13, 'quantity' => 1300]],
                    ],
                ],
                'materials' => [
                    ['item' => 'NX-1R8-NEX', 'name' => 'Rod PCu 8mm plain annealed', 'unit' => 'kg', 'isRod' => true, 'quantity' => 2.3452],
                    ['item' => 'TIN_ANODE', 'name' => 'Tin anode', 'unit' => 'kg', 'isRod' => false, 'quantity' => 0.13],
                ],
                'usedBy' => [],
            ],
            self::rounded($data),
        );
    }

    public function test_item_used_by_and_alternatives(): void
    {
        $data = $this->get($this->app(), 'item?item=NX-1C16');

        self::assertSame(1000, $data['length']);
        self::assertSame([['item' => 'NM-1C16', 'stage' => 'mica_taped', 'qtyPerUnit' => 1, 'unit' => 'm']], $data['usedBy']);
        $steps = Payload::objList($data['steps']);
        self::assertSame(['NX-1W1.6', 'NX-16W.3', 'NX-32W.3', 'NX-1C16'], array_column($steps, 'item'));
        self::assertSame([['machine' => 'BU2', 'orders' => 11]], Payload::objList($steps[3]['operations'])[0]['alternatives']);
        self::assertNull($steps[1]['kg']);
    }

    public function test_item_required_and_unknown(): void
    {
        $app = $this->app();
        foreach (['item', 'item?item=', 'item?item=%20', 'item?length=5'] as $path) {
            [$status, $error] = $this->getError($app, $path);
            self::assertSame(422, $status);
            self::assertSame('CONDUCTOR_ITEM_REQUIRED', $error['code']);
        }

        [$status, $error] = $this->getError($app, 'item?item=NX-1C17');
        self::assertSame(404, $status);
        self::assertSame('CONDUCTOR_ITEM_NOT_FOUND', $error['code']);
    }

    public function test_a_validation_error_wins_over_a_missing_store(): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_PROCESS_DB_PATH' => FixtureStore::missingPath()]);

        [$status, $error] = $this->getError($app, 'items?stage=nope');

        self::assertSame(422, $status);
        self::assertSame('CONDUCTOR_PARAM_INVALID', $error['code']);
    }

    public function test_store_with_another_schema_version_is_503(): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_PROCESS_DB_PATH' => FixtureStore::createWithVersion(2)]);

        [$status, $error] = $this->getError($app, 'machines');

        self::assertSame(503, $status);
        self::assertSame('CONDUCTOR_STORE_UNAVAILABLE', $error['code']);
    }

    public function test_a_file_that_is_not_a_store_is_503(): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_PROCESS_DB_PATH' => FixtureStore::createGarbage()]);

        [$status, $error] = $this->getError($app, 'run');

        self::assertSame(503, $status);
        self::assertSame('CONDUCTOR_STORE_UNAVAILABLE', $error['code']);
    }

    public function test_the_store_is_opened_read_only(): void
    {
        $path = FixtureStore::create();
        $app = TestAppFactory::create(['CONDUCTOR_PROCESS_DB_PATH' => $path]);
        $before = hash_file('sha256', $path);

        $this->get($app, 'run');
        $this->get($app, 'item?item=NM-1C16');

        self::assertSame($before, hash_file('sha256', $path));
        self::assertSame([], glob($path . '*-journal') ?: []);
    }

    /**
     * Floats rounded to 9 places, so products like 1300 x 0.0018 compare exactly; whole floats stay as JSON decoded them.
     */
    private static function rounded(mixed $value): mixed
    {
        if (is_float($value)) {
            $r = round($value, 9);

            return floor($r) === $r ? (int) $r : $r;
        }
        if (is_array($value)) {
            return array_map(self::rounded(...), $value);
        }

        return $value;
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(): App
    {
        return TestAppFactory::create(['CONDUCTOR_PROCESS_DB_PATH' => FixtureStore::create()]);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed> the envelope's data
     */
    private function get(App $app, string $path): array
    {
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));
        $body = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        self::assertTrue($body['success']);

        return Payload::obj($body['data']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{int, array<string, mixed>} status and the envelope's error
     */
    private function getError(App $app, string $path): array
    {
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));
        $body = TestAppFactory::json($response);
        self::assertFalse($body['success']);

        return [$response->getStatusCode(), Payload::obj($body['error'])];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $repo = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repo);
        $repo->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }
}
