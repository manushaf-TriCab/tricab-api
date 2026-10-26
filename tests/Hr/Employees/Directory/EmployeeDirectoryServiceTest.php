<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\Employees\Directory;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Hr\Employees\Directory\EmployeeDirectoryService;
use TriCab\Api\Hr\Employees\Directory\EmployeeListCriteria;
use TriCab\Api\Hr\Employees\Directory\EmployeeNotFound;
use TriCab\Api\Hr\Employees\Directory\InMemoryEmployeeDirectory;
use TriCab\Api\Hr\Employees\Directory\InMemoryRestrictedReadAudit;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

final class EmployeeDirectoryServiceTest extends TestCase
{
    private InMemoryEmployeeDirectory $directory;
    private InMemoryRestrictedReadAudit $audit;
    private EmployeeDirectoryService $service;

    protected function setUp(): void
    {
        $this->directory = EmployeeDirectoryFixtures::directory();
        $this->audit = new InMemoryRestrictedReadAudit();
        $this->service = new EmployeeDirectoryService($this->directory, $this->audit);
    }

    public function test_status_defaults_to_current_and_past_and_all_widen_it(): void
    {
        self::assertSame(7, $this->service->list(new EmployeeListCriteria())['pagination']['totalItems']);
        self::assertSame(['E008'], $this->codes(new EmployeeListCriteria(status: 'past')));
        self::assertCount(8, $this->codes(new EmployeeListCriteria(status: 'all', pageSize: 50)));
    }

    public function test_the_manager_filter_ignores_case(): void
    {
        self::assertSame(['E003', 'E004'], $this->codes(new EmployeeListCriteria(manager: 'aNN', sortBy: 'code')));
        self::assertSame(['E003', 'E004', 'E008'], $this->codes(new EmployeeListCriteria(manager: 'ANN', status: 'all', sortBy: 'code')));
    }

    public function test_search_matches_code_login_and_names_and_filters_combine(): void
    {
        self::assertSame(['E003', 'E004'], $this->codes(new EmployeeListCriteria(search: 'ray', sortBy: 'code')));
        self::assertSame(['E005'], $this->codes(new EmployeeListCriteria(search: 'e005')));
        self::assertSame(['E002'], $this->codes(new EmployeeListCriteria(search: 'ANN')));
        self::assertSame(['E004'], $this->codes(new EmployeeListCriteria(search: 'ray', department: 'DistCent')));
        self::assertSame(['E003'], $this->codes(new EmployeeListCriteria(search: 'ray', factory: 'SYD', department: 'OpsExtru')));
    }

    public function test_paging_and_sorting(): void
    {
        $page = $this->service->list(new EmployeeListCriteria(page: 2, pageSize: 3, sortBy: 'code', sortDirection: 'desc'));

        self::assertSame(['E004', 'E003', 'E002'], array_column($page['items'], 'code'));
        self::assertSame(['page' => 2, 'pageSize' => 3, 'totalItems' => 7, 'totalPages' => 3], $page['pagination']);
    }

    public function test_facets_count_the_status_filter_only(): void
    {
        $facets = $this->service->list(new EmployeeListCriteria(department: 'DistCent'))['facets'];

        self::assertSame(
            [['value' => 'DistCent', 'count' => 2], ['value' => 'OpsExtru', 'count' => 4], ['value' => 'OpsHRSaf', 'count' => 1]],
            $facets['departments'],
        );
        self::assertSame([['value' => 'MELB', 'count' => 5], ['value' => 'SYD', 'count' => 2]], $facets['factories']);
        self::assertSame([['value' => 'OpsExtru', 'count' => 1]], $this->service->list(new EmployeeListCriteria(status: 'past'))['facets']['departments']);
    }

    /**
     * @param callable(): EmployeeListCriteria $make
     */
    #[DataProvider('invalidCriteria')]
    public function test_invalid_criteria_are_refused(callable $make): void
    {
        $this->expectException(InvalidArgumentException::class);

        $make();
    }

    /**
     * @return array<string, array{callable(): EmployeeListCriteria}>
     */
    public static function invalidCriteria(): array
    {
        return [
            'page' => [static fn () => new EmployeeListCriteria(page: 0)],
            'pageSize' => [static fn () => new EmployeeListCriteria(pageSize: 101)],
            'status' => [static fn () => new EmployeeListCriteria(status: 'gone')],
            'sortBy' => [static fn () => new EmployeeListCriteria(sortBy: 'salary')],
            'sortDirection' => [static fn () => new EmployeeListCriteria(sortDirection: 'up')],
        ];
    }

    public function test_a_dangling_manager_has_a_null_name_and_an_empty_chain(): void
    {
        $detail = $this->service->detail('E005', null);

        self::assertSame('ghost', $detail['managerLogin']);
        self::assertNull($detail['managerName']);
        self::assertSame([], $detail['managerChain']);
    }

    public function test_the_manager_chain_runs_to_the_top_and_resolves_logins_case_insensitively(): void
    {
        $detail = $this->service->detail('E003', null);

        self::assertSame('Ann Lee', $detail['managerName']);
        self::assertSame(['E002', 'E001'], array_column(self::rows($detail['managerChain']), 'code'));
    }

    public function test_a_manager_cycle_stops_instead_of_looping(): void
    {
        $detail = $this->service->detail('E006', null);

        self::assertSame(['E007'], array_column(self::rows($detail['managerChain']), 'code'));
    }

    public function test_a_manager_who_is_their_own_manager_does_not_appear_in_their_own_chain(): void
    {
        $this->directory->withEmployee(EmployeeDirectoryFixtures::employee('E009', 'solo', 'Sol', 'Oh', 'X', 'Y', 'SOLO', '2020-01-01'));

        self::assertSame([], $this->service->detail('E009', null)['managerChain']);
    }

    public function test_a_very_deep_chain_is_cut_at_ten(): void
    {
        $directory = new InMemoryEmployeeDirectory();
        for ($i = 1; $i <= 15; $i++) {
            $directory->withEmployee(EmployeeDirectoryFixtures::employee('D' . $i, 'u' . $i, 'U', (string) $i, 'X', 'Y', 'u' . ($i + 1), '2020-01-01'));
        }

        $chain = (new EmployeeDirectoryService($directory, $this->audit))->detail('D1', null)['managerChain'];

        self::assertCount(10, self::rows($chain));
    }

    public function test_direct_reports_are_current_people_who_name_this_login_whatever_the_case(): void
    {
        $detail = $this->service->detail('E002', null);

        self::assertSame(['E004', 'E003'], array_column(self::rows($detail['directReports']), 'code'));
        self::assertSame(['code', 'login', 'displayName', 'department', 'factory'], array_keys(self::rows($detail['directReports'])[0]));
    }

    public function test_without_the_restricted_permission_the_key_is_absent_and_nothing_is_audited(): void
    {
        $detail = $this->service->detail('E002', null);

        self::assertArrayNotHasKey('restricted', $detail);
        self::assertStringNotContainsString('1980-02-03', json_encode($detail, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->audit->events);
    }

    public function test_with_it_the_block_is_returned_and_the_read_is_audited_once(): void
    {
        $detail = $this->service->detail('E002', new ActorContext(7, 'p', 'hr@tricab.com'));

        self::assertSame('1980-02-03', self::rows([$detail['restricted']])[0]['dateOfBirth']);
        self::assertSame(12.5, self::rows([$detail['restricted']])[0]['annualLeaveRemaining']);
        self::assertSame(
            [['actorUserId' => 7, 'employeeCode' => 'E002', 'endpoint' => 'GET /api/v1/hr/employees/{code}']],
            $this->audit->events,
        );
    }

    public function test_an_employee_with_no_restricted_row_gets_a_block_of_nulls_and_is_still_audited(): void
    {
        $detail = $this->service->detail('E003', new ActorContext(7, 'p', 'hr@tricab.com'));

        self::assertCount(11, self::rows([$detail['restricted']])[0]);
        self::assertSame([null], array_values(array_unique(self::rows([$detail['restricted']])[0], SORT_REGULAR)));
        self::assertCount(1, $this->audit->events);
    }

    public function test_an_unknown_code_is_not_found_and_is_not_audited(): void
    {
        try {
            $this->service->detail('NOPE', new ActorContext(7, 'p', 'hr@tricab.com'));
            self::fail('Expected EmployeeNotFound.');
        } catch (EmployeeNotFound) {
            self::assertSame([], $this->audit->events);
        }
    }

    public function test_sync_runs_summarise_the_latest_and_the_last_success(): void
    {
        $this->directory->withSyncRuns([
            ['id' => 3, 'mode' => 'apply', 'status' => 'guarded', 'warning' => 'Short refresh.'],
            ['id' => 2, 'mode' => 'dry-run', 'status' => 'succeeded', 'warning' => null],
            ['id' => 1, 'mode' => 'apply', 'status' => 'succeeded', 'warning' => null],
        ]);

        $result = $this->service->syncRuns();

        self::assertCount(3, $result['runs']);
        self::assertSame(1, self::rows([$result['summary']['lastSuccessfulRun']])[0]['id']);
        self::assertSame('guarded', $result['summary']['lastRunStatus']);
        self::assertSame('Short refresh.', $result['summary']['lastRunWarning']);
        self::assertSame('2026-10-15', $result['summary']['latestPayrollDate']);
        self::assertSame(7, $result['summary']['currentCount']);
    }

    public function test_no_runs_gives_an_empty_summary(): void
    {
        $result = $this->service->syncRuns();

        self::assertSame([], $result['runs']);
        self::assertNull($result['summary']['lastSuccessfulRun']);
        self::assertNull($result['summary']['lastRunStatus']);
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

    /** @return list<string> */
    private function codes(EmployeeListCriteria $criteria): array
    {
        return array_map(static fn (mixed $code): string => is_string($code) ? $code : '', array_column($this->service->list($criteria)['items'], 'code'));
    }
}
