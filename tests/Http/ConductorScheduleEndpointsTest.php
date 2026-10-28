<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Conductor\Schedule\FixtureStore;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Conductor\Module;
use TriCab\Core\Identity\Contract\UserRepository;

final class ConductorScheduleEndpointsTest extends TestCase
{
    private const BASE = '/api/v1/conductor/schedule/';

    protected function tearDown(): void
    {
        FixtureStore::cleanup();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endpoints(): iterable
    {
        foreach (['run', 'restock', 'jobs', 'ops', 'op?id=1', 'batches', 'batch?id=1', 'machines/load', 'stock', 'issues'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('endpoints')]
    public function test_unauthenticated_is_rejected(string $path): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false', 'CONDUCTOR_SCHEDULE_DB_PATH' => FixtureStore::create()]);
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    #[DataProvider('endpoints')]
    public function test_without_the_permission_is_forbidden(string $path): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_SCHEDULE_DB_PATH' => FixtureStore::create()]);
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', 'CABLE_ROUTES.READ']);
        $response = $app->handle(TestAppFactory::request('GET', self::BASE . $path));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('INSUFFICIENT_PERMISSION', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    #[DataProvider('endpoints')]
    public function test_with_only_the_permission_is_allowed(string $path): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_SCHEDULE_DB_PATH' => FixtureStore::create()]);
        $this->replacePermissions($app, [Module::PERMISSION_READ]);

        $this->get($app, $path);
    }

    #[DataProvider('endpoints')]
    public function test_missing_store_is_503_without_a_path(string $path): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_SCHEDULE_DB_PATH' => FixtureStore::missingPath()]);

        [$status, $error] = $this->getError($app, $path);

        self::assertSame(503, $status);
        self::assertSame('CONDUCTOR_SCHEDULE_STORE_UNAVAILABLE', $error['code']);
        self::assertStringNotContainsString('/', Payload::str($error['message']), 'the message never contains a path');
    }

    public function test_a_store_with_no_run_is_503(): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_SCHEDULE_DB_PATH' => FixtureStore::createWithoutRun()]);

        [$status, $error] = $this->getError($app, 'run');

        self::assertSame(503, $status);
        self::assertSame('CONDUCTOR_SCHEDULE_STORE_UNAVAILABLE', $error['code']);
    }

    public function test_a_store_of_another_schema_version_is_503(): void
    {
        $app = TestAppFactory::create(['CONDUCTOR_SCHEDULE_DB_PATH' => FixtureStore::createWithVersion(2)]);

        [$status, $error] = $this->getError($app, 'stock');

        self::assertSame(503, $status);
        self::assertSame('CONDUCTOR_SCHEDULE_STORE_UNAVAILABLE', $error['code']);
    }

    public function test_run(): void
    {
        $data = $this->get($this->app(), 'run');

        self::assertSame('2026-10-28', Payload::obj($data['run'])['today']);
        self::assertSame(1, Payload::obj($data['run'])['schemaVersion']);
        self::assertSame(['order', 'invoice', 'shortPeriod'], array_keys(Payload::obj($data['r86'])));
        self::assertSame(6004.5, Payload::obj(Payload::obj($data['r86'])['order'])['unplacedWeeklyM']);
        self::assertSame(4, Payload::obj($data['counts'])['jobs']);
        self::assertSame(3, Payload::obj($data['counts'])['issuesWarning']);
        self::assertCount(3, Payload::objList($data['sources']));
        $settings = Payload::obj($data['settings']);
        self::assertSame(3, $settings['refillWeeks']);
        self::assertSame([], $settings['shifts'], 'an empty object decodes as an empty array here; it is {} on the wire');
    }

    public function test_the_empty_settings_object_is_an_object_on_the_wire(): void
    {
        $response = $this->app()->handle(TestAppFactory::request('GET', self::BASE . 'run'));

        self::assertStringContainsString('"shifts":{}', (string) $response->getBody());
    }

    public function test_restock(): void
    {
        $data = $this->get($this->app(), 'restock?status=release');

        self::assertSame(['NX-1C16'], array_column(Payload::objList($data['rows']), 'item'));
        self::assertSame(6402.304, Payload::objList($data['rows'])[0]['releaseKg']);
        self::assertCount(2, Payload::objList($data['bySize']));
        self::assertSame(1, Payload::obj($data['totals'])['releaseItems']);
    }

    public function test_jobs_and_ops_paging(): void
    {
        $jobs = $this->get($this->app(), 'jobs?pageSize=3&page=2');
        self::assertSame([4], array_column(Payload::objList($jobs['jobs']), 'jobId'));
        self::assertSame(2, $jobs['totalPages']);

        $ops = $this->get($this->app(), 'ops?machine=bu2&late=false');
        self::assertSame([2], array_column(Payload::objList($ops['ops']), 'opId'));
        self::assertCount(2, Payload::objList(Payload::objList($ops['ops'])[0]['passes']));
    }

    public function test_op_and_batch_detail(): void
    {
        $op = Payload::obj($this->get($this->app(), 'op?id=1')['op']);
        self::assertSame([1, 2], array_column(Payload::objList($op['passes']), 'passId'));

        $batch = Payload::obj($this->get($this->app(), 'batch?id=2')['batch']);
        self::assertCount(1, Payload::objList($batch['draws']));
        self::assertSame([1], array_column(Payload::objList($batch['feeders']), 'batchId'));

        self::assertSame([1, 2], array_column(Payload::objList($this->get($this->app(), 'batches')['batches']), 'batchId'));
    }

    public function test_machines_load_stock_and_issues(): void
    {
        $load = $this->get($this->app(), 'machines/load?weeks=8');
        self::assertSame(8, $load['weeks']);
        self::assertSame('2026-10-26', $load['from']);
        self::assertCount(12, Payload::objList($load['machines']));

        $stock = $this->get($this->app(), 'stock');
        self::assertSame('NX-1R8-NEX', Payload::obj($stock['rod'])['item']);
        self::assertTrue(Payload::obj($stock['rod'])['low']);
        self::assertCount(3, Payload::objList($stock['rows']));

        $issues = $this->get($this->app(), 'issues?severity=warning');
        self::assertCount(3, Payload::objList($issues['issues']));
        self::assertCount(3, Payload::objList($issues['byKind']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unprocessable(): iterable
    {
        yield 'restock status' => ['restock?status=sleeping', 'CONDUCTOR_PARAM_INVALID'];
        yield 'restock material' => ['restock?material=brass', 'CONDUCTOR_PARAM_INVALID'];
        yield 'restock scheduled' => ['restock?scheduled=maybe', 'CONDUCTOR_PARAM_INVALID'];
        yield 'jobs status' => ['jobs?status=done', 'CONDUCTOR_PARAM_INVALID'];
        yield 'jobs late' => ['jobs?late=2', 'CONDUCTOR_PARAM_INVALID'];
        yield 'jobs page' => ['jobs?page=0', 'CONDUCTOR_PARAM_INVALID'];
        yield 'jobs page size' => ['jobs?pageSize=201', 'CONDUCTOR_PARAM_INVALID'];
        yield 'ops machine' => ['ops?machine=ZZ9', 'CONDUCTOR_PARAM_INVALID'];
        yield 'ops page size' => ['ops?pageSize=abc', 'CONDUCTOR_PARAM_INVALID'];
        yield 'op id missing' => ['op', 'CONDUCTOR_OP_REQUIRED'];
        yield 'op id blank' => ['op?id=%20', 'CONDUCTOR_OP_REQUIRED'];
        yield 'op id text' => ['op?id=abc', 'CONDUCTOR_PARAM_INVALID'];
        yield 'batch id missing' => ['batch', 'CONDUCTOR_BATCH_REQUIRED'];
        yield 'batch id text' => ['batch?id=1.5', 'CONDUCTOR_PARAM_INVALID'];
        yield 'weeks' => ['machines/load?weeks=5', 'CONDUCTOR_PARAM_INVALID'];
        yield 'issues kind' => ['issues?kind=bogus', 'CONDUCTOR_PARAM_INVALID'];
        yield 'issues severity' => ['issues?severity=error', 'CONDUCTOR_PARAM_INVALID'];
    }

    #[DataProvider('unprocessable')]
    public function test_bad_params_are_422(string $path, string $code): void
    {
        [$status, $error] = $this->getError($this->app(), $path);

        self::assertSame(422, $status);
        self::assertSame($code, $error['code']);
    }

    public function test_unknown_ids_are_404(): void
    {
        [$status, $error] = $this->getError($this->app(), 'op?id=99');
        self::assertSame(404, $status);
        self::assertSame('CONDUCTOR_OP_NOT_FOUND', $error['code']);

        [$status, $error] = $this->getError($this->app(), 'batch?id=99');
        self::assertSame(404, $status);
        self::assertSame('CONDUCTOR_BATCH_NOT_FOUND', $error['code']);
    }

    public function test_the_api_never_writes_to_the_store(): void
    {
        $path = FixtureStore::create();
        $app = TestAppFactory::create(['CONDUCTOR_SCHEDULE_DB_PATH' => $path]);
        $before = hash_file('sha256', $path);

        $this->get($app, 'run');
        $this->get($app, 'ops');
        $this->get($app, 'machines/load');

        self::assertSame($before, hash_file('sha256', $path));
        self::assertSame([], glob($path . '*-journal') ?: []);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(): App
    {
        return TestAppFactory::create(['CONDUCTOR_SCHEDULE_DB_PATH' => FixtureStore::create()]);
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
