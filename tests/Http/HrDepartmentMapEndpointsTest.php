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
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Hr\Employees\Directory\EmployeeDirectoryFixtures;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The department map over HTTP: who may read and edit it, that unmapped codes
 * stay visible, that every change is audited, and what the directory then shows.
 */
final class HrDepartmentMapEndpointsTest extends TestCase
{
    private const MAP = '/api/v1/hr/department-map';
    private const LIST = '/api/v1/hr/employees';

    public function test_reading_needs_the_directory_permission_and_editing_needs_the_sync_permission(): void
    {
        $body = ['kind' => 'department', 'raw' => 'OpsExtru', 'cleanName' => 'Extrusion'];

        self::assertSame(200, $this->get($this->app([HrPermissions::EMPLOYEES_READ]))->getStatusCode());
        self::assertSame(403, $this->get($this->app([HrPermissions::EMPLOYEES_SYNC, HrPermissions::EMPLOYEES_RESTRICTED_READ]))->getStatusCode());

        foreach ([[HrPermissions::EMPLOYEES_READ, HrPermissions::EMPLOYEES_RESTRICTED_READ], ['ONBOARDING.PEOPLE.MANAGE']] as $without) {
            $app = $this->app($without);
            self::assertSame(403, $this->put($app, $body)->getStatusCode());
            self::assertSame([], $this->directory($app)->mapEvents);
        }
        self::assertSame(200, $this->put($this->app([HrPermissions::EMPLOYEES_SYNC]), $body)->getStatusCode());
    }

    public function test_every_raw_value_is_listed_and_unmapped_ones_are_flagged(): void
    {
        $data = $this->data($this->get($this->app([HrPermissions::EMPLOYEES_READ])));

        $entries = self::rows($data['entries'] ?? null);
        self::assertSame(
            ['department|DistCent', 'department|OpsExtru', 'department|OpsHRSaf', 'factory|MELB', 'factory|SYD'],
            array_map(static fn (array $entry): string => self::label($entry), $entries),
        );
        foreach ($entries as $entry) {
            self::assertFalse($entry['mapped']);
            self::assertNull($entry['cleanName']);
        }
        self::assertSame(['unmapped' => 5, 'total' => 5], $data['summary']);
        self::assertSame(5, $entries[1]['employees'], 'OpsExtru: E002 E003 E006 E007 and the past E008');
        self::assertSame(4, $entries[1]['currentEmployees']);
    }

    public function test_a_mapping_is_saved_audited_and_swaps_the_directory_to_clean_names(): void
    {
        $app = $this->app([HrPermissions::EMPLOYEES_READ, HrPermissions::EMPLOYEES_SYNC]);

        $saved = $this->data($this->put($app, ['kind' => 'department', 'raw' => ' OpsExtru ', 'cleanName' => ' Extrusion ', 'costCentre' => 'CC-100']));
        self::assertSame(['kind' => 'department', 'raw' => 'OpsExtru', 'cleanName' => 'Extrusion', 'costCentre' => 'CC-100', 'mapped' => true, 'changed' => true], $saved);
        $this->put($app, ['kind' => 'factory', 'raw' => 'SYD', 'cleanName' => 'Sydney']);

        $list = $this->data($app->handle(TestAppFactory::request('GET', self::LIST . '?sortBy=code')));
        $items = self::rows($list['items'] ?? null);
        self::assertSame('Extrusion', $items[1]['department'], 'E002 shows the clean name');
        self::assertSame('OpsExtru', $items[1]['departmentRaw'], 'the raw value stays on the record');
        self::assertSame('DistCent', $items[3]['department'], 'an unmapped code shows its raw value');
        self::assertSame('Sydney', $items[2]['factory']);
        self::assertSame('MELB', $items[0]['factory']);

        $facets = self::assoc($list['facets'] ?? null);
        self::assertSame(['DistCent', 'Extrusion', 'OpsHRSaf'], array_column(self::rows($facets['departments'] ?? null), 'value'));
        self::assertSame(['MELB', 'Sydney'], array_column(self::rows($facets['factories'] ?? null), 'value'));

        $codes = fn (string $query): array => array_column(self::rows($this->data($app->handle(TestAppFactory::request('GET', self::LIST . $query)))['items'] ?? null), 'code');
        self::assertSame(['E002', 'E003', 'E006', 'E007'], $codes('?department=Extrusion&sortBy=code'));
        self::assertSame([], $codes('?department=OpsExtru'), 'the raw code no longer filters once mapped');
        self::assertSame(['E004', 'E005'], $codes('?department=DistCent&sortBy=code'));
        self::assertSame(['E003', 'E004'], $codes('?factory=Sydney&sortBy=code'));

        $detail = $this->data($app->handle(TestAppFactory::request('GET', self::LIST . '/E002')));
        self::assertSame('Extrusion', $detail['department']);
        self::assertSame('OpsExtru', $detail['departmentRaw']);

        $entries = self::rows($this->data($this->get($app))['entries'] ?? null);
        self::assertTrue($entries[1]['mapped']);
        self::assertSame('CC-100', $entries[1]['costCentre']);
        self::assertSame(
            [
                ['actorUserId' => 1, 'kind' => 'department', 'raw' => 'OpsExtru', 'action' => 'created', 'oldCleanName' => null, 'oldCostCentre' => null, 'newCleanName' => 'Extrusion', 'newCostCentre' => 'CC-100'],
                ['actorUserId' => 1, 'kind' => 'factory', 'raw' => 'SYD', 'action' => 'created', 'oldCleanName' => null, 'oldCostCentre' => null, 'newCleanName' => 'Sydney', 'newCostCentre' => null],
            ],
            $this->directory($app)->mapEvents,
        );
    }

    public function test_updates_and_clears_are_audited_and_a_no_op_is_not(): void
    {
        $app = $this->app([HrPermissions::EMPLOYEES_SYNC]);
        $this->put($app, ['kind' => 'department', 'raw' => 'DistCent', 'cleanName' => 'Distribution']);

        $same = $this->data($this->put($app, ['kind' => 'department', 'raw' => 'DistCent', 'cleanName' => 'Distribution']));
        self::assertFalse($same['changed']);

        $this->put($app, ['kind' => 'department', 'raw' => 'DistCent', 'cleanName' => 'Distribution Centre']);
        $cleared = $this->data($this->put($app, ['kind' => 'department', 'raw' => 'DistCent']));
        self::assertFalse($cleared['mapped']);

        $untouched = $this->data($this->put($app, ['kind' => 'department', 'raw' => 'OpsHRSaf']));
        self::assertFalse($untouched['changed'], 'clearing an unmapped code changes nothing');

        $events = $this->directory($app)->mapEvents;
        self::assertSame(['created', 'updated', 'cleared'], array_column($events, 'action'));
        self::assertSame('Distribution', $events[1]['oldCleanName']);
        self::assertSame('Distribution Centre', $events[2]['oldCleanName']);
        self::assertNull($events[2]['newCleanName']);
    }

    public function test_bad_requests_are_refused_and_write_nothing(): void
    {
        $app = $this->app([HrPermissions::EMPLOYEES_SYNC]);

        foreach ([
            ['kind' => 'site', 'raw' => 'OpsExtru', 'cleanName' => 'X'],
            ['kind' => 'department', 'raw' => '  ', 'cleanName' => 'X'],
            ['kind' => 'department', 'raw' => 'OpsExtru', 'cleanName' => str_repeat('x', 101)],
            ['kind' => 'department', 'raw' => 'OpsExtru', 'costCentre' => 'CC-1'],
            ['kind' => 'department', 'raw' => 'OpsExtru', 'cleanName' => ['x']],
            [],
        ] as $body) {
            $response = $this->put($app, $body);
            self::assertSame(422, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
            self::assertSame('VALIDATION_FAILED', $this->code($response));
        }

        $unknown = $this->put($app, ['kind' => 'department', 'raw' => 'NoSuchCode', 'cleanName' => 'X']);
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame('HR_DEPARTMENT_CODE_UNKNOWN', $this->code($unknown));
        self::assertSame([], $this->directory($app)->mapEvents);
    }

    public function test_a_mapped_value_that_left_payroll_stays_in_the_list(): void
    {
        $app = $this->app([HrPermissions::EMPLOYEES_READ, HrPermissions::EMPLOYEES_SYNC]);
        $this->put($app, ['kind' => 'department', 'raw' => 'DistCent', 'cleanName' => 'Distribution']);
        $directory = $this->directory($app);
        $directory->withEmployee(EmployeeDirectoryFixtures::employee('E004', 'ben', 'Ben', 'Ray', 'Elsewhere', 'SYD', 'Ann', '2021-07-01'));
        $directory->withEmployee(EmployeeDirectoryFixtures::employee('E005', 'cat', 'Cat', 'Kim', 'Elsewhere', 'MELB', 'ghost', '2022-01-10'));

        $entries = self::rows($this->data($this->get($app))['entries'] ?? null);
        $gone = array_values(array_filter($entries, static fn (array $entry): bool => $entry['raw'] === 'DistCent'));

        self::assertCount(1, $gone);
        self::assertSame(0, $gone[0]['employees']);
        self::assertSame('Distribution', $gone[0]['cleanName']);
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
        $users->replacePermissions(1, ['account.login', ...$permissions], ['EMPLOYEE']);
        EmployeeDirectoryFixtures::seed($this->directory($app));

        return $app;
    }

    /** @param App<ContainerInterface> $app */
    private function directory(App $app): InMemoryEmployeeDirectory
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $directory = $container->get(EmployeeDirectory::class);
        self::assertInstanceOf(InMemoryEmployeeDirectory::class, $directory);

        return $directory;
    }

    /** @param App<ContainerInterface> $app */
    private function get(App $app): ResponseInterface
    {
        return $app->handle(TestAppFactory::request('GET', self::MAP));
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $body
     */
    private function put(App $app, array $body): ResponseInterface
    {
        return $app->handle(TestAppFactory::request('PUT', self::MAP)->withParsedBody($body));
    }

    /** @return array<string, mixed> */
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
        self::assertIsString($error['code'] ?? null);

        return $error['code'];
    }

    /** @param array<string, mixed> $entry */
    private static function label(array $entry): string
    {
        $kind = $entry['kind'] ?? null;
        $raw = $entry['raw'] ?? null;

        return (is_string($kind) ? $kind : '') . '|' . (is_string($raw) ? $raw : '');
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> */
        return array_values($value);
    }

    /** @return array<string, mixed> */
    private static function assoc(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> */
        return $value;
    }
}
