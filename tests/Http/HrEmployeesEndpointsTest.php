<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Hr\Employees\Directory\EmployeeDirectory;
use TriCab\Api\Hr\Employees\Directory\HrPermissions;
use TriCab\Api\Hr\Employees\Directory\InMemoryEmployeeDirectory;
use TriCab\Api\Hr\Employees\Directory\InMemoryRestrictedReadAudit;
use TriCab\Api\Hr\Employees\Directory\RestrictedReadAudit;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Hr\Employees\Directory\EmployeeDirectoryFixtures;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The HR Employees routes over HTTP: which permission opens each, what the
 * restricted block does and does not do, and that only a returned block is audited.
 */
final class HrEmployeesEndpointsTest extends TestCase
{
    private const LIST = '/api/v1/hr/employees';
    private const RUNS = '/api/v1/hr/employees/sync-runs';
    private const DETAIL = '/api/v1/hr/employees/E002';

    private const READ = HrPermissions::EMPLOYEES_READ;
    private const RESTRICTED = HrPermissions::EMPLOYEES_RESTRICTED_READ;
    private const SYNC = HrPermissions::EMPLOYEES_SYNC;

    private const ROUTES = [
        'GET /api/v1/hr/employees' => self::READ,
        'GET /api/v1/hr/employees/sync-runs' => self::SYNC,
        'GET /api/v1/hr/employees/{code}' => self::READ,
    ];

    public function test_every_hr_route_is_in_the_table(): void
    {
        $registered = [];
        foreach (TestAppFactory::create()->getRouteCollector()->getRoutes() as $route) {
            if (!str_starts_with($route->getPattern(), '/api/v1/hr')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $registered[] = $method . ' ' . $route->getPattern();
            }
        }
        $expected = array_keys(self::ROUTES);
        sort($registered);
        sort($expected);

        self::assertSame($expected, $registered, 'A new HR route must be listed here with its permission, and no employee write route may exist.');
    }

    public function test_each_route_opens_to_its_permission_alone(): void
    {
        $everything = ['account.login', 'QUOTE.READ', 'ONBOARDING.PEOPLE.MANAGE', self::READ, self::RESTRICTED, self::SYNC];
        foreach (self::ROUTES as $route => $permission) {
            [, $pattern] = explode(' ', $route, 2);
            $path = str_replace('{code}', 'E002', $pattern);

            $own = $this->app(['account.login', $permission])->handle(TestAppFactory::request('GET', $path));
            self::assertSame(200, $own->getStatusCode(), $route . ' refused its own permission.');

            $others = array_values(array_diff($everything, [$permission]));
            $refused = $this->app($others)->handle(TestAppFactory::request('GET', $path));
            self::assertSame(403, $refused->getStatusCode(), $route . ' opened without ' . $permission . '.');
            self::assertSame('INSUFFICIENT_PERMISSION', $this->code($refused));
        }
    }

    public function test_the_restricted_permission_alone_does_not_open_the_directory(): void
    {
        $app = $this->app(['account.login', self::RESTRICTED]);

        foreach ([self::LIST, self::DETAIL, self::RUNS] as $path) {
            self::assertSame(403, $app->handle(TestAppFactory::request('GET', $path))->getStatusCode(), $path);
        }
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        foreach ([self::LIST, self::DETAIL, self::RUNS] as $path) {
            self::assertSame(401, $app->handle(TestAppFactory::request('GET', $path))->getStatusCode(), $path);
        }
    }

    public function test_the_list_defaults_to_current_people_and_carries_the_item_shape_pagination_and_facets(): void
    {
        $data = $this->data($this->app([self::READ])->handle(TestAppFactory::request('GET', self::LIST . '?sortBy=code')));

        self::assertSame(['page' => 1, 'pageSize' => 25, 'totalItems' => 7, 'totalPages' => 1], $data['pagination']);
        $items = self::rows($data['items'] ?? null);
        self::assertSame(['E001', 'E002', 'E003', 'E004', 'E005', 'E006', 'E007'], array_column($items, 'code'));
        self::assertSame([
            'code', 'login', 'firstName', 'lastName', 'displayName', 'department', 'factory', 'workEmail', 'managerLogin',
            'managerName', 'dateHired', 'isCurrent', 'lastSeenInPayroll', 'missingFromSourceAt', 'linkedUser',
        ], array_keys($items[1]));
        self::assertSame('Chris Owen', $items[1]['managerName']);
        self::assertNull($items[4]['managerName'], 'a dangling manager shows no name');
        self::assertSame(['departments', 'factories'], array_keys(self::assoc($data['facets'] ?? null)));
        self::assertStringNotContainsString('1980-02-03', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_the_list_filters_by_status_manager_search_department_and_factory(): void
    {
        $app = $this->app([self::READ, self::RESTRICTED]);

        self::assertSame(['E008'], $this->codes($app, '?status=past'));
        self::assertSame(['E001', 'E002', 'E003', 'E004', 'E005', 'E006', 'E007', 'E008'], $this->codes($app, '?status=all&sortBy=code'));
        self::assertSame(['E003', 'E004'], $this->codes($app, '?manager=aNN&sortBy=code'));
        self::assertSame(['E003', 'E004'], $this->codes($app, '?q=ray&sortBy=code'));
        self::assertSame(['E004', 'E005'], $this->codes($app, '?department=DistCent&sortBy=code'));
        self::assertSame(['E003', 'E004'], $this->codes($app, '?factory=SYD&sortBy=code'));
        self::assertSame(['E004'], $this->codes($app, '?q=ray&department=DistCent'));
        self::assertSame(['E002'], $this->codes($app, '?q=ann'));
    }

    public function test_the_list_never_audits_even_for_a_caller_who_could_read_restricted_data(): void
    {
        $app = $this->app([self::READ, self::RESTRICTED]);

        $app->handle(TestAppFactory::request('GET', self::LIST));

        self::assertSame([], $this->audit($app)->events);
    }

    public function test_bad_list_parameters_are_unprocessable(): void
    {
        $app = $this->app([self::READ]);

        foreach (['?status=gone', '?page=0', '?pageSize=500', '?sortBy=salary', '?sortDirection=up'] as $query) {
            $response = $app->handle(TestAppFactory::request('GET', self::LIST . $query));
            self::assertSame(422, $response->getStatusCode(), $query);
            self::assertSame('VALIDATION_FAILED', $this->code($response));
        }
    }

    public function test_the_detail_has_no_restricted_key_without_the_restricted_permission_and_is_not_audited(): void
    {
        $app = $this->app([self::READ]);

        $response = $app->handle(TestAppFactory::request('GET', self::DETAIL));
        $body = (string) $response->getBody();
        $data = $this->data($response);

        self::assertArrayNotHasKey('restricted', $data);
        foreach (['1980-02-03', 'ann@example.test', '1 Fake St', '0400 000 001', 'dateOfBirth', 'annualLeaveRemaining'] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
        self::assertSame([], $this->audit($app)->events);
        self::assertSame(['E001'], array_column(self::rows($data['managerChain'] ?? null), 'code'));
        self::assertSame(['E004', 'E003'], array_column(self::rows($data['directReports'] ?? null), 'code'));
    }

    public function test_the_detail_returns_and_audits_the_restricted_block_for_a_holder(): void
    {
        $app = $this->app([self::READ, self::RESTRICTED]);

        $data = $this->data($app->handle(TestAppFactory::request('GET', self::DETAIL)->withHeader('X-Request-Id', 'req-1')));

        self::assertSame([
            'personalEmail' => 'ann@example.test', 'mobile' => '0400 000 001', 'street' => '1 Fake St',
            'suburb' => 'Faketon', 'state' => 'VIC', 'postcode' => '3000', 'dateOfBirth' => '1980-02-03',
            'annualLeaveRemaining' => 12.5, 'longServiceLeaveRemaining' => 3.5, 'sickLeaveRemaining' => 8.25,
            'leaveAsOf' => '2026-10-15',
        ], $data['restricted']);
        self::assertSame(
            [['actorUserId' => 1, 'employeeCode' => 'E002', 'endpoint' => 'GET /api/v1/hr/employees/{code}']],
            $this->audit($app)->events,
        );

        $app->handle(TestAppFactory::request('GET', self::DETAIL));
        self::assertCount(2, $this->audit($app)->events, 'every read is a row');
    }

    public function test_an_unknown_code_is_404_and_leaves_no_audit_row(): void
    {
        $app = $this->app([self::READ, self::RESTRICTED]);

        $response = $app->handle(TestAppFactory::request('GET', self::LIST . '/NOPE'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('HR_EMPLOYEE_NOT_FOUND', $this->code($response));
        self::assertSame([], $this->audit($app)->events);
    }

    public function test_the_detail_survives_a_manager_cycle(): void
    {
        $data = $this->data($this->app([self::READ])->handle(TestAppFactory::request('GET', self::LIST . '/E006')));

        self::assertSame(['E007'], array_column(self::rows($data['managerChain'] ?? null), 'code'));
    }

    public function test_sync_runs_return_the_runs_and_a_summary(): void
    {
        $app = $this->app([self::SYNC]);
        $this->directory($app)->withSyncRuns([
            ['id' => 2, 'mode' => 'apply', 'status' => 'guarded', 'warning' => 'Short refresh.'],
            ['id' => 1, 'mode' => 'apply', 'status' => 'succeeded', 'warning' => null],
        ]);

        $data = $this->data($app->handle(TestAppFactory::request('GET', self::RUNS)));

        self::assertCount(2, self::rows($data['runs'] ?? null));
        $summary = $data['summary'] ?? null;
        self::assertIsArray($summary);
        self::assertSame('guarded', $summary['lastRunStatus']);
        self::assertSame('Short refresh.', $summary['lastRunWarning']);
        self::assertSame(7, $summary['currentCount']);
        self::assertSame('2026-10-15', $summary['latestPayrollDate']);
    }

    public function test_the_module_has_no_write_routes(): void
    {
        $app = $this->app([self::READ, self::RESTRICTED, self::SYNC]);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach ([self::LIST, self::DETAIL] as $path) {
                self::assertContains($app->handle(TestAppFactory::request($method, $path))->getStatusCode(), [404, 405], $method . ' ' . $path);
            }
        }
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions === [] ? ['account.login'] : ['account.login', ...$permissions], ['EMPLOYEE']);

        EmployeeDirectoryFixtures::seed($this->directory($app));

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function directory(App $app): InMemoryEmployeeDirectory
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $directory = $container->get(EmployeeDirectory::class);
        self::assertInstanceOf(InMemoryEmployeeDirectory::class, $directory);

        return $directory;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function audit(App $app): InMemoryRestrictedReadAudit
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $audit = $container->get(RestrictedReadAudit::class);
        self::assertInstanceOf(InMemoryRestrictedReadAudit::class, $audit);

        return $audit;
    }

    /**
     * @param App<ContainerInterface> $app
     *
     * @return list<string>
     */
    private function codes(App $app, string $query): array
    {
        $data = $this->data($app->handle(TestAppFactory::request('GET', self::LIST . $query)));

        return array_map(static fn (mixed $code): string => is_string($code) ? $code : '', array_column(self::rows($data['items'] ?? null), 'code'));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $payload = TestAppFactory::json($response);
        self::assertIsArray($payload['data'] ?? null, (string) $response->getBody());

        /** @var array<string, mixed> */
        return $payload['data'];
    }

    private function code(ResponseInterface $response): string
    {
        $error = TestAppFactory::json($response)['error'] ?? null;
        self::assertIsArray($error);

        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> */
        return array_values($value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function assoc(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> */
        return $value;
    }
}
