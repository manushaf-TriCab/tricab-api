<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Conductor\Process;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Conductor\Process\Persistence\InMemoryConductorProcessRepository;
use TriCab\Api\Conductor\Process\Persistence\SqliteConductorProcessRepository;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Auth\ConductorProcessErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessInvalidRequest;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessItemNotFound;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessMachineNotFound;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessStoreUnavailable;
use TriCab\Core\Conductor\Process\Application\Service\ConductorProcessQueryService;

final class ConductorProcessPersistenceTest extends TestCase
{
    protected function tearDown(): void
    {
        FixtureStore::cleanup();
    }

    public function test_the_store_reads_back_every_table_as_written(): void
    {
        $repository = new SqliteConductorProcessRepository(FixtureStore::create());

        self::assertSame(FixtureData::run(), $repository->run());
        self::assertSame(FixtureData::machines(), $repository->machines());
        self::assertSame(self::sorted(FixtureData::items(), 'item'), self::sorted($repository->items(), 'item'));
        self::assertSame(
            [['machine' => 'TN1', 'operation' => 'ANNEAL', 'orders' => 56], ['machine' => 'TN1', 'operation' => 'TINNING', 'orders' => 56], ['machine' => 'TN1', 'operation' => 'WIRE_DRAW', 'orders' => 2]],
            self::sorted($repository->machineOperations('TN1'), 'operation'),
        );
    }

    public function test_the_store_answers_like_the_in_memory_repository(): void
    {
        $sqlite = new SqliteConductorProcessRepository(FixtureStore::create());
        $memory = FixtureData::inMemory();
        $all = array_column(FixtureData::items(), 'item');

        foreach (['RB2', 'TN1', 'BU2', 'BU7', 'TP3'] as $machine) {
            self::assertSame(self::sorted($memory->itemsRoutedOn($machine), 'item'), self::sorted($sqlite->itemsRoutedOn($machine), 'item'), $machine);
            self::assertEqualsWithDelta(self::canonical($memory->altMachinesOn($machine)), self::canonical($sqlite->altMachinesOn($machine)), 1e-12, $machine);
        }
        foreach ($all as $item) {
            self::assertEqualsWithDelta(self::canonical($memory->needs($item)), self::canonical($sqlite->needs($item)), 1e-12, $item);
            self::assertEqualsWithDelta(self::canonical($memory->inputsConsuming($item)), self::canonical($sqlite->inputsConsuming($item)), 1e-12, $item);
        }
        self::assertEqualsWithDelta(self::canonical($memory->operations($all)), self::canonical($sqlite->operations($all)), 1e-12);
        self::assertEqualsWithDelta(self::canonical($memory->inputs($all)), self::canonical($sqlite->inputs($all)), 1e-12);
        self::assertEqualsWithDelta(self::canonical($memory->altMachines($all)), self::canonical($sqlite->altMachines($all)), 1e-12);
        self::assertEqualsWithDelta(self::canonical($memory->materials(['NX-1R8-NEX', 'MICA_TAPE', 'NOPE'])), self::canonical($sqlite->materials(['NX-1R8-NEX', 'MICA_TAPE', 'NOPE'])), 1e-12);
        self::assertSame(self::sorted($memory->itemsNamed(['NX-1C16', 'DX-1W.5']), 'item'), self::sorted($sqlite->itemsNamed(['NX-1C16', 'DX-1W.5']), 'item'));
        self::assertSame([], $sqlite->itemsNamed([]));
        self::assertSame([], $sqlite->operations([]));
        self::assertSame([], $sqlite->inputs([]));
        self::assertSame([], $sqlite->altMachines([]));
        self::assertSame([], $sqlite->materials([]));

        $service = new ConductorProcessQueryService($sqlite);
        $reference = new ConductorProcessQueryService($memory);
        self::assertEqualsWithDelta($reference->item('NM-1C16', '1000'), $service->item('NM-1C16', '1000'), 1e-9);
        self::assertEqualsWithDelta($reference->machine('BU2'), $service->machine('BU2'), 1e-9);
        self::assertEqualsWithDelta($reference->items(null, null, null, null, null), $service->items(null, null, null, null, null), 1e-9);
    }

    public function test_item_lookup_is_case_insensitive_and_exact(): void
    {
        $repository = new SqliteConductorProcessRepository(FixtureStore::create());

        self::assertSame('NX-1C16', $repository->findItem('nx-1c16')['item'] ?? null);
        self::assertNull($repository->findItem('NX-1C1'));
        self::assertNull($repository->findItem('NX-1C16%'));
    }

    public function test_steps_operations_come_back_in_seq_order(): void
    {
        $repository = new SqliteConductorProcessRepository(FixtureStore::create());

        self::assertSame([1, 2], array_column($repository->operations(['DX-1W.5']), 'seq'));
    }

    public function test_an_empty_run_table_is_not_an_error(): void
    {
        $repository = new SqliteConductorProcessRepository(FixtureStore::createWithoutRun());

        self::assertNull($repository->run());
        self::assertCount(15, $repository->machines());
    }

    /**
     * @return iterable<string, array{\Closure(): string}>
     */
    public static function unavailableStores(): iterable
    {
        yield 'missing' => [static fn (): string => FixtureStore::missingPath()];
        yield 'not a database' => [static fn (): string => FixtureStore::createGarbage()];
        yield 'other schema version' => [static fn (): string => FixtureStore::createWithVersion(2)];
    }

    /**
     * @param \Closure(): string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableStores')]
    public function test_an_unusable_store_is_unavailable(\Closure $path): void
    {
        $repository = new SqliteConductorProcessRepository($path());

        $this->expectException(ConductorProcessStoreUnavailable::class);
        $repository->machines();
    }

    public function test_an_empty_sqlite_file_is_unavailable(): void
    {
        $path = FixtureStore::missingPath();
        touch($path);
        try {
            $this->expectException(ConductorProcessStoreUnavailable::class);
            (new SqliteConductorProcessRepository($path))->run();
        } finally {
            unlink($path);
        }
    }

    public function test_the_store_is_opened_read_only(): void
    {
        $path = FixtureStore::create();
        $repository = new SqliteConductorProcessRepository($path);
        $repository->run();

        $connection = (new \ReflectionProperty(SqliteConductorProcessRepository::class, 'pdo'))->getValue($repository);
        self::assertInstanceOf(\PDO::class, $connection);
        $this->expectException(\PDOException::class);
        $connection->exec('DELETE FROM cd_item');
    }

    public function test_settings_resolve_a_relative_path_against_the_api_root(): void
    {
        $default = Settings::fromEnvironment([]);
        self::assertSame('var/conductor/conductor-process.sqlite', $default->conductorProcessDbPath);
        self::assertSame('var/conductor/conductor-process.sqlite', Settings::fromEnvironment(['CONDUCTOR_PROCESS_DB_PATH' => '  '])->conductorProcessDbPath);
        self::assertSame('/tmp/x.sqlite', Settings::fromEnvironment(['CONDUCTOR_PROCESS_DB_PATH' => '/tmp/x.sqlite'])->conductorProcessDbPath);

        $reflection = new \ReflectionProperty(SqliteConductorProcessRepository::class, 'file');
        $relative = SqliteConductorProcessRepository::fromSettings($default);
        self::assertSame(dirname(__DIR__, 3) . '/var/conductor/conductor-process.sqlite', $reflection->getValue($relative));

        $absolute = SqliteConductorProcessRepository::fromSettings(Settings::fromEnvironment(['CONDUCTOR_PROCESS_DB_PATH' => '/tmp/x.sqlite']));
        self::assertSame('/tmp/x.sqlite', $reflection->getValue($absolute));
    }

    public function test_in_memory_repository_records_calls_and_can_be_unavailable(): void
    {
        $repository = FixtureData::inMemory();
        $repository->run();
        $repository->findItem('nx-1c16');
        self::assertSame(['run', 'findItem'], $repository->calls);

        $this->expectException(ConductorProcessStoreUnavailable::class);
        (new InMemoryConductorProcessRepository(available: false))->items();
    }

    public function test_error_mapper(): void
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        $responder = $container->get(JsonResponder::class);
        self::assertInstanceOf(JsonResponder::class, $responder);
        $mapper = new ConductorProcessErrorMapper($responder);

        $cases = [
            [new ConductorProcessInvalidRequest('CONDUCTOR_PARAM_INVALID', 'length must be a number.'), 422, 'CONDUCTOR_PARAM_INVALID'],
            [new ConductorProcessInvalidRequest('CONDUCTOR_ITEM_REQUIRED', 'item is required.'), 422, 'CONDUCTOR_ITEM_REQUIRED'],
            [new ConductorProcessItemNotFound('x'), 404, 'CONDUCTOR_ITEM_NOT_FOUND'],
            [new ConductorProcessMachineNotFound('x'), 404, 'CONDUCTOR_MACHINE_NOT_FOUND'],
            [new ConductorProcessStoreUnavailable('/secret/path/conductor.sqlite is missing'), 503, 'CONDUCTOR_STORE_UNAVAILABLE'],
            [new \RuntimeException('/secret/path boom'), 503, 'CONDUCTOR_STORE_UNAVAILABLE'],
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

    /**
     * The rows in a stable order, whatever order the repository gave them in.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function canonical(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp(json_encode(array_map(self::key(...), $a), JSON_THROW_ON_ERROR), json_encode(array_map(self::key(...), $b), JSON_THROW_ON_ERROR)));

        return $rows;
    }

    /** Floats rounded so a REAL that went through SQLite sorts with the value it was written from. */
    private static function key(mixed $value): mixed
    {
        return is_float($value) ? round($value, 9) : $value;
    }

    /**
     * @template T of array<string, mixed>
     * @param list<T> $rows
     * @return list<T>
     */
    private static function sorted(array $rows, string $key): array
    {
        usort($rows, static fn (array $a, array $b): int => [$a[$key], $a] <=> [$b[$key], $b]);

        return $rows;
    }
}
