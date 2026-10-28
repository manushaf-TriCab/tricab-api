<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Conductor\Schedule;

use Closure;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use TriCab\Api\Conductor\Schedule\Persistence\InMemoryConductorScheduleRepository;
use TriCab\Api\Conductor\Schedule\Persistence\SqliteConductorScheduleRepository;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Auth\ConductorScheduleErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleBatchNotFound;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleInvalidRequest;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleOpNotFound;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleStoreUnavailable;
use TriCab\Core\Conductor\Schedule\Application\Service\ConductorScheduleQueryService;

final class ConductorSchedulePersistenceTest extends TestCase
{
    protected function tearDown(): void
    {
        FixtureStore::cleanup();
    }

    public function test_the_store_reads_back_every_table_as_written(): void
    {
        $repository = new SqliteConductorScheduleRepository(FixtureStore::create());

        self::assertEquals(FixtureData::run(), $repository->run());
        self::assertEquals(self::sorted(FixtureData::sources(), 'name'), self::sorted($repository->sources(), 'name'));
        self::assertEquals(self::sorted(FixtureData::settings(), 'key'), self::sorted($repository->settings(), 'key'));
        self::assertEquals(FixtureData::machines(), $repository->machines(), 'in stage order');
        self::assertEquals(self::sorted(FixtureData::restock(), 'item'), self::sorted($repository->restock(), 'item'));
        self::assertEquals(self::sorted(FixtureData::demand(), 'item'), self::sorted($repository->demand(), 'item'));
        self::assertEquals(self::sorted(FixtureData::jobs(), 'jobId'), self::sorted($repository->jobs(), 'jobId'));
        self::assertEquals(self::sorted(FixtureData::ops(), 'opId'), self::sorted($repository->ops(), 'opId'));
        self::assertEquals(self::sorted(FixtureData::passes(), 'passId'), self::sorted($repository->passes(), 'passId'));
        self::assertEquals(self::sorted(FixtureData::batches(), 'batchId'), self::sorted($repository->batches(), 'batchId'));
        self::assertEquals(self::sorted(FixtureData::stock(), 'item'), self::sorted($repository->stock(), 'item'));
        self::assertCount(count(FixtureData::r86()), $repository->r86());
        self::assertCount(count(FixtureData::draws()), $repository->draws());
        self::assertCount(72, $repository->capacity());
        self::assertCount(6, $repository->load());
        self::assertCount(6, $repository->issues());
    }

    public function test_booleans_lists_and_nulls_come_back_typed(): void
    {
        $repository = new SqliteConductorScheduleRepository(FixtureStore::create());

        $restock = array_column($repository->restock(), null, 'item');
        self::assertTrue($restock['NM-1C16']['mica']);
        self::assertFalse($restock['NX-1C16']['mica']);
        self::assertTrue($restock['NX-1C16']['scheduled']);
        self::assertNull($restock['NX-1C16']['note']);
        self::assertSame('not scheduled: taping not modelled', $restock['NM-1C16']['note']);

        $passes = array_column($repository->passes(), null, 'passId');
        self::assertSame(['BU1', 'BU2'], $passes[1]['eligible']);
        self::assertNull($passes[1]['parentPassId']);
        self::assertSame(1, $passes[2]['batchId']);

        $ops = array_column($repository->ops(), null, 'opId');
        self::assertNull($ops[3]['rodKg']);
        self::assertIsFloat($ops[1]['rodKg']);

        $settings = array_column($repository->settings(), 'value', 'key');
        self::assertSame(3, $settings['refill_weeks']);
        self::assertSame('13w', $settings['rate_window']);
    }

    public function test_the_store_answers_like_the_in_memory_repository(): void
    {
        $sqlite = new ConductorScheduleQueryService(new SqliteConductorScheduleRepository(FixtureStore::create()));
        $memory = new ConductorScheduleQueryService(FixtureData::inMemory());

        $calls = [
            'run' => static fn (ConductorScheduleQueryService $s): array => $s->run(),
            'restock' => static fn (ConductorScheduleQueryService $s): array => $s->restock(null, null, null),
            'restock tinned' => static fn (ConductorScheduleQueryService $s): array => $s->restock('ok', 'tinned', 'true'),
            'jobs' => static fn (ConductorScheduleQueryService $s): array => $s->jobs(null, null, null, null),
            'ops' => static fn (ConductorScheduleQueryService $s): array => $s->ops(null, null, null, null, null),
            'ops machine' => static fn (ConductorScheduleQueryService $s): array => $s->ops('rb1', 'false', null, null, null),
            'op' => static fn (ConductorScheduleQueryService $s): array => $s->op('2'),
            'batches' => static fn (ConductorScheduleQueryService $s): array => $s->batches(),
            'batch 1' => static fn (ConductorScheduleQueryService $s): array => $s->batch('1'),
            'batch 2' => static fn (ConductorScheduleQueryService $s): array => $s->batch('2'),
            'load' => static fn (ConductorScheduleQueryService $s): array => $s->machineLoad('8'),
            'stock' => static fn (ConductorScheduleQueryService $s): array => $s->stock(),
            'issues' => static fn (ConductorScheduleQueryService $s): array => $s->issues(null, null),
        ];
        foreach ($calls as $name => $call) {
            self::assertEqualsWithDelta($call($memory), $call($sqlite), 1e-9, $name);
        }
    }

    public function test_an_empty_run_table_is_unavailable(): void
    {
        $repository = new SqliteConductorScheduleRepository(FixtureStore::createWithoutRun());

        $this->expectException(ConductorScheduleStoreUnavailable::class);
        $repository->run();
    }

    /**
     * @return iterable<string, array{Closure(): string}>
     */
    public static function unavailableStores(): iterable
    {
        yield 'missing' => [static fn (): string => FixtureStore::missingPath()];
        yield 'not a database' => [static fn (): string => FixtureStore::createGarbage()];
        yield 'other schema version' => [static fn (): string => FixtureStore::createWithVersion(2)];
        yield 'no run' => [static fn (): string => FixtureStore::createWithoutRun()];
    }

    /**
     * @param Closure(): string $path
     */
    #[DataProvider('unavailableStores')]
    public function test_an_unusable_store_is_unavailable(Closure $path): void
    {
        $repository = new SqliteConductorScheduleRepository($path());

        $this->expectException(ConductorScheduleStoreUnavailable::class);
        $repository->machines();
    }

    public function test_an_empty_sqlite_file_is_unavailable(): void
    {
        $path = FixtureStore::missingPath();
        touch($path);
        try {
            $this->expectException(ConductorScheduleStoreUnavailable::class);
            (new SqliteConductorScheduleRepository($path))->run();
        } finally {
            unlink($path);
        }
    }

    public function test_the_store_is_opened_read_only(): void
    {
        $path = FixtureStore::create();
        $repository = new SqliteConductorScheduleRepository($path);
        $repository->run();

        $connection = (new ReflectionProperty(SqliteConductorScheduleRepository::class, 'pdo'))->getValue($repository);
        self::assertInstanceOf(PDO::class, $connection);
        $this->expectException(PDOException::class);
        $connection->exec('DELETE FROM cs_op');
    }

    public function test_the_fixture_schema_is_the_engine_schema(): void
    {
        self::assertFileExists(FixtureStore::schemaPath());
        self::assertStringEndsWith('core/python/conductor_schedule/schema.sql', str_replace('\\', '/', FixtureStore::schemaPath()));
        $schema = file_get_contents(FixtureStore::schemaPath());
        self::assertIsString($schema);
        self::assertStringContainsString('PRAGMA user_version = 1', $schema);
    }

    public function test_settings_resolve_a_relative_path_against_the_api_root(): void
    {
        $default = Settings::fromEnvironment([]);
        self::assertSame('var/conductor/conductor-schedule.sqlite', $default->conductorScheduleDbPath);
        self::assertSame('var/conductor/conductor-schedule.sqlite', Settings::fromEnvironment(['CONDUCTOR_SCHEDULE_DB_PATH' => '  '])->conductorScheduleDbPath);
        self::assertSame('/tmp/x.sqlite', Settings::fromEnvironment(['CONDUCTOR_SCHEDULE_DB_PATH' => '/tmp/x.sqlite'])->conductorScheduleDbPath);

        $reflection = new ReflectionProperty(SqliteConductorScheduleRepository::class, 'file');
        $relative = SqliteConductorScheduleRepository::fromSettings($default);
        self::assertSame(dirname(__DIR__, 3) . '/var/conductor/conductor-schedule.sqlite', $reflection->getValue($relative));

        $absolute = SqliteConductorScheduleRepository::fromSettings(Settings::fromEnvironment(['CONDUCTOR_SCHEDULE_DB_PATH' => '/tmp/x.sqlite']));
        self::assertSame('/tmp/x.sqlite', $reflection->getValue($absolute));
    }

    public function test_in_memory_repository_records_calls_and_can_be_unavailable(): void
    {
        $repository = FixtureData::inMemory();
        $repository->run();
        $repository->ops();
        self::assertSame(['run', 'ops'], $repository->calls);

        $this->expectException(ConductorScheduleStoreUnavailable::class);
        (new InMemoryConductorScheduleRepository(available: false))->jobs();
    }

    public function test_error_mapper(): void
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        $responder = $container->get(JsonResponder::class);
        self::assertInstanceOf(JsonResponder::class, $responder);
        $mapper = new ConductorScheduleErrorMapper($responder);

        $cases = [
            [new ConductorScheduleInvalidRequest('CONDUCTOR_PARAM_INVALID', 'weeks must be 4, 8 or 26.'), 422, 'CONDUCTOR_PARAM_INVALID'],
            [new ConductorScheduleInvalidRequest('CONDUCTOR_OP_REQUIRED', 'id is required.'), 422, 'CONDUCTOR_OP_REQUIRED'],
            [new ConductorScheduleInvalidRequest('CONDUCTOR_BATCH_REQUIRED', 'id is required.'), 422, 'CONDUCTOR_BATCH_REQUIRED'],
            [new ConductorScheduleOpNotFound('x'), 404, 'CONDUCTOR_OP_NOT_FOUND'],
            [new ConductorScheduleBatchNotFound('x'), 404, 'CONDUCTOR_BATCH_NOT_FOUND'],
            [new ConductorScheduleStoreUnavailable('/secret/path/conductor.sqlite is missing'), 503, 'CONDUCTOR_SCHEDULE_STORE_UNAVAILABLE'],
            [new RuntimeException('/secret/path boom'), 503, 'CONDUCTOR_SCHEDULE_STORE_UNAVAILABLE'],
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
     * @template T of array<string, mixed>
     * @param list<T> $rows
     * @return list<T>
     */
    private static function sorted(array $rows, string $key): array
    {
        usort($rows, static fn (array $a, array $b): int => $a[$key] <=> $b[$key]);

        return $rows;
    }
}
