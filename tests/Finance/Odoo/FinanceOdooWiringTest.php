<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Odoo;

use DateTimeImmutable;
use DateTimeZone;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Odoo\HttpOdooClient;
use TriCab\Api\Finance\Odoo\InMemoryOdooRecordStore;
use TriCab\Api\Finance\Odoo\InMemoryOdooSyncRuns;
use TriCab\Api\Finance\Odoo\UndeployedOdooClient;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\StubOdooClient;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Finance\Shared\Odoo\OdooClient;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordStore;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncJob;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRun;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRuns;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The Odoo settings, the container and the three routes, over the stubbed Odoo: no test
 * here touches the network. The client is real only when Odoo is configured, the daily job
 * is registered only then, and each route is gated and answers as its action says.
 */
final class FinanceOdooWiringTest extends TestCase
{
    private const ODOO = [
        'ODOO_URL' => 'https://odoo.example.test',
        'ODOO_API_KEY' => 'dummy-key',
        'ODOO_COMPANY_IDS' => '2,4',
    ];

    private StubOdooClient $odoo;

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function app(array $env, bool $stub = true): App
    {
        $app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true'] + $env);
        $this->odoo = new StubOdooClient();
        if ($stub) {
            $container = $app->getContainer();
            self::assertInstanceOf(Container::class, $container);
            $container->set(OdooClient::class, $this->odoo);
        }

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissions
     */
    private function grant(App $app, array $permissions): void
    {
        $users = $app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(App $app, string $method, string $path, ?array $body = null): array
    {
        $request = TestAppFactory::request($method, $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    private function seedOdoo(): void
    {
        $this->odoo->put('res.company', ['id' => 2, 'name' => 'Example AU Pty Ltd', 'active' => true, 'write_date' => '2026-09-01 00:00:00']);
        $this->odoo->put('res.partner', ['id' => 11, 'name' => 'zeta Supplies', 'ref' => 'V-3', 'active' => true, 'write_date' => '2026-10-01 01:00:00']);
        $this->odoo->put('res.partner', ['id' => 12, 'name' => 'Alpha Cables', 'ref' => 'V-1', 'active' => true, 'write_date' => '2026-10-02 01:00:00']);
        $this->odoo->put('res.partner', ['id' => 13, 'name' => 'Mid Freight', 'ref' => 'V-2', 'active' => true, 'write_date' => '2026-10-03 01:00:00']);
    }

    /**
     * @param array<string, mixed> $json
     * @return list<string>
     */
    private static function names(array $json): array
    {
        $data = $json['data'];
        self::assertIsArray($data);
        $items = $data['items'];
        self::assertIsArray($items);

        $names = [];
        foreach ($items as $item) {
            $names[] = self::text(self::dig($item, 'name'));
        }

        return $names;
    }

    /** One value deep in a decoded answer; null when any step is missing. */
    private static function dig(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    public function test_unconfigured_the_client_is_undeployed_and_no_job_is_registered(): void
    {
        foreach ([[], ['ODOO_URL' => 'http://odoo.example.test'] + self::ODOO] + array_map(
            static fn (string $missing): array => array_diff_key(self::ODOO, [$missing => true]),
            array_keys(self::ODOO),
        ) as $env) {
            $container = TestAppFactory::create($env)->getContainer();
            self::assertInstanceOf(UndeployedOdooClient::class, $container->get(OdooClient::class), json_encode($env) ?: '');
            $registry = $container->get(FinanceJobRegistry::class);
            self::assertInstanceOf(FinanceJobRegistry::class, $registry);
            self::assertSame([], $registry->all(), 'no nightly job while Odoo is not configured');
        }
    }

    public function test_configured_the_client_is_http_and_the_daily_job_is_registered(): void
    {
        $container = TestAppFactory::create(self::ODOO)->getContainer();

        self::assertInstanceOf(HttpOdooClient::class, $container->get(OdooClient::class));
        self::assertInstanceOf(InMemoryOdooRecordStore::class, $container->get(OdooRecordStore::class));
        self::assertInstanceOf(InMemoryOdooSyncRuns::class, $container->get(OdooSyncRuns::class));
        $registry = $container->get(FinanceJobRegistry::class);
        self::assertInstanceOf(FinanceJobRegistry::class, $registry);
        $jobs = $registry->all();
        self::assertCount(1, $jobs);
        self::assertInstanceOf(OdooSyncJob::class, $jobs[0]);
    }

    public function test_the_routes_are_gated(): void
    {
        $app = $this->app(self::ODOO);

        $this->grant($app, []);
        self::assertSame(403, $this->call($app, 'GET', '/api/v1/finance/reference/vendors')[0]);
        self::assertSame(403, $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full'])[0]);
        self::assertSame(403, $this->call($app, 'GET', '/api/v1/finance/reference/status')[0]);

        $this->grant($app, [Module::PERMISSION_AP2_ACCESS]);
        self::assertSame(200, $this->call($app, 'GET', '/api/v1/finance/reference/vendors')[0]);
        self::assertSame(403, $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full'])[0], 'reading a list is not running a sync');
        self::assertSame(403, $this->call($app, 'GET', '/api/v1/finance/reference/status')[0]);

        $this->grant($app, [Module::PERMISSION_ADMIN]);
        self::assertSame(200, $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full'])[0]);
        self::assertSame(200, $this->call($app, 'GET', '/api/v1/finance/reference/status')[0]);
    }

    public function test_with_the_module_off_there_are_no_reference_routes(): void
    {
        $app = TestAppFactory::create(self::ODOO);
        $this->grant($app, [Module::PERMISSION_ADMIN, Module::PERMISSION_AP2_ACCESS]);

        self::assertSame(404, $this->call($app, 'GET', '/api/v1/finance/reference/vendors')[0]);
        self::assertSame(404, $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full'])[0]);
    }

    public function test_an_unknown_list_is_404_and_a_bad_updated_since_is_422(): void
    {
        $app = $this->app(self::ODOO);
        $this->grant($app, [Module::PERMISSION_AP2_ACCESS]);

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/reference/customers');
        self::assertSame(404, $status);
        self::assertSame('FINANCE_NOT_FOUND', self::dig($json, 'error', 'code'));

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/reference/vendors?updated_since=not-a-date');
        self::assertSame(422, $status);
        self::assertSame('FINANCE_INPUT_INVALID', self::dig($json, 'error', 'code'));
    }

    public function test_a_full_sync_fills_the_lists_and_vendors_come_back_sorted_by_name(): void
    {
        $app = $this->app(self::ODOO);
        $this->seedOdoo();
        $this->grant($app, [Module::PERMISSION_ADMIN, Module::PERMISSION_AP2_ACCESS]);

        [$status, $json] = $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full']);
        self::assertSame(200, $status);
        self::assertSame('succeeded', self::dig($json, 'data', 'run', 'status'));
        self::assertSame('full', self::dig($json, 'data', 'run', 'mode'));

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/reference/vendors');
        self::assertSame(200, $status);
        self::assertSame(['Alpha Cables', 'Mid Freight', 'zeta Supplies'], self::names($json), 'by name, ignoring case');
        self::assertSame('vendors', self::dig($json, 'data', 'list'));
        self::assertNotNull(self::dig($json, 'data', 'synced_at'));
        self::assertIsString(self::dig($json, 'data', 'server_time'));
        self::assertSame(['Example AU Pty Ltd'], self::names($this->call($app, 'GET', '/api/v1/finance/reference/companies')[1]));

        // updated_since far in the future returns nothing; a valid ISO time with Z is accepted.
        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/reference/vendors?updated_since=2999-01-01T00:00:00Z');
        self::assertSame(200, $status);
        self::assertSame([], self::names($json));

        $audit = $app->getContainer()->get(FinanceAuditLog::class);
        self::assertInstanceOf(InMemoryFinanceAuditLog::class, $audit);
        self::assertCount(1, $audit->events);
        self::assertSame('finance.odoo.sync', $audit->events[0]->action);
    }

    public function test_an_incremental_sync_asks_odoo_from_the_newest_write_date_and_removes_nothing(): void
    {
        $app = $this->app(self::ODOO);
        $this->seedOdoo();
        $this->grant($app, [Module::PERMISSION_ADMIN, Module::PERMISSION_AP2_ACCESS]);
        self::assertSame(200, $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full'])[0]);

        $this->odoo->remove('res.partner', 11);
        $this->odoo->calls = [];
        [$status, $json] = $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'incremental']);
        self::assertSame(200, $status);
        self::assertSame('incremental', self::dig($json, 'data', 'run', 'mode'));
        self::assertContains(['write_date', '>=', '2026-10-03 01:00:00'], $this->odoo->callsFor('res.partner')[0]['domain']);
        self::assertSame(['Alpha Cables', 'Mid Freight', 'zeta Supplies'], self::names($this->call($app, 'GET', '/api/v1/finance/reference/vendors')[1]), 'only a full sweep removes');

        self::assertSame(200, $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full'])[0]);
        self::assertSame(['Alpha Cables', 'Mid Freight'], self::names($this->call($app, 'GET', '/api/v1/finance/reference/vendors')[1]));
    }

    public function test_the_sync_refuses_a_bad_mode_a_busy_run_and_an_unconfigured_odoo(): void
    {
        $app = $this->app(self::ODOO);
        $this->grant($app, [Module::PERMISSION_ADMIN]);

        [$status, $json] = $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'auto']);
        self::assertSame(422, $status);
        self::assertSame('FINANCE_INPUT_INVALID', self::dig($json, 'error', 'code'));
        self::assertSame(422, $this->call($app, 'POST', '/api/v1/finance/reference/sync', [])[0], 'the mode is required');

        $runs = $app->getContainer()->get(OdooSyncRuns::class);
        self::assertInstanceOf(OdooSyncRuns::class, $runs);
        self::assertTrue($runs->start('a1', OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE, null, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
        [$status, $json] = $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full']);
        self::assertSame(409, $status);
        self::assertSame('FINANCE_ODOO_SYNC_BUSY', self::dig($json, 'error', 'code'));
        self::assertSame([], $this->odoo->calls, 'nothing was read while another run held the lock');

        $unconfigured = $this->app([], false);
        $this->grant($unconfigured, [Module::PERMISSION_ADMIN]);
        [$status, $json] = $this->call($unconfigured, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full']);
        self::assertSame(503, $status);
        self::assertSame('FINANCE_ODOO_NOT_CONFIGURED', self::dig($json, 'error', 'code'));
    }

    public function test_a_failed_sync_is_502_with_the_run_and_its_reason(): void
    {
        $app = $this->app(self::ODOO);
        $this->seedOdoo();
        $this->odoo->failures['account.account'] = new \TriCab\Core\Finance\Shared\Odoo\OdooError('Odoo answered 401 for account.account.');
        $this->grant($app, [Module::PERMISSION_ADMIN, Module::PERMISSION_AP2_ACCESS]);

        [$status, $json] = $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full']);
        self::assertSame(502, $status);
        self::assertSame('FINANCE_ODOO_SYNC_FAILED', self::dig($json, 'error', 'code'));
        self::assertSame('failed', self::dig($json, 'data', 'run', 'status'));
        // All-or-nothing is the database transaction's (AzureSqlOdooTest and OdooSyncServiceTest
        // show it); the in-memory twins here have no transaction to roll back.
        self::assertStringContainsString('401', self::text(self::dig($json, 'data', 'run', 'error')));
    }

    public function test_the_status_route_reports_configuration_counts_runs_and_the_keys_expiry_never_the_key(): void
    {
        $expires = (new DateTimeImmutable('now', new DateTimeZone('Australia/Melbourne')))->modify('+10 days')->format('Y-m-d');
        $app = $this->app(self::ODOO + ['ODOO_API_KEY_EXPIRES' => $expires]);
        $this->seedOdoo();
        $this->grant($app, [Module::PERMISSION_ADMIN]);
        self::assertSame(200, $this->call($app, 'POST', '/api/v1/finance/reference/sync', ['mode' => 'full'])[0]);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/finance/reference/status'));
        self::assertSame(200, $response->getStatusCode());
        $data = TestAppFactory::json($response)['data'];
        self::assertIsArray($data);
        self::assertTrue($data['configured']);
        self::assertSame([2, 4], $data['company_ids']);
        self::assertSame($expires, $data['key_expires']);
        self::assertIsInt($data['key_days_left']);
        self::assertTrue($data['key_expires_soon'], 'ten days away is within 30');
        self::assertSame(3, self::dig($data, 'counts', 'vendors'));
        self::assertSame('succeeded', self::dig($data, 'last_success', 'status'));
        self::assertIsArray($data['recent_runs']);
        self::assertCount(1, $data['recent_runs']);
        $response->getBody()->rewind();
        self::assertStringNotContainsString('dummy-key', (string) $response->getBody());
    }
}
