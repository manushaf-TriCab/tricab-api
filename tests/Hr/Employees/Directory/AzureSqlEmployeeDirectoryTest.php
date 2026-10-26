<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\Employees\Directory;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Hr\Employees\Directory\AzureSqlEmployeeDirectory;
use TriCab\Api\Hr\Employees\Directory\AzureSqlRestrictedReadAudit;
use TriCab\Api\Hr\Employees\Directory\EmployeeListCriteria;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

/** The SQL the directory sends, against a fake connection: what it reads, filters on and writes. */
final class AzureSqlEmployeeDirectoryTest extends TestCase
{
    private const ROW = [
        'EmployeeCode' => 'E002', 'UserLogin' => 'ANN', 'FirstName' => 'Ann', 'LastName' => 'Lee', 'DisplayName' => 'Ann Lee',
        'DepartmentRaw' => 'OpsExtru', 'FactoryRaw' => 'MELB', 'WorkEmail' => 'ann@tricab.com', 'ManagerLogin' => 'ghost',
        'ManagerName' => null, 'DateHired' => '2015-03-02', 'IsCurrent' => 1, 'LastSeenInPayroll' => '2026-10-15',
        'MissingFromSourceAt' => null, 'LinkedUserPublicId' => 'AAAAAAAA-0000-4000-8000-000000000001', 'LinkedUserDisplayName' => 'Ann Lee',
    ];

    public function test_no_base_query_touches_the_restricted_table(): void
    {
        $connection = new FakeSqlConnection([self::ROW]);
        $directory = new AzureSqlEmployeeDirectory($connection);

        $directory->search(new EmployeeListCriteria(search: 'ann', manager: 'X'));
        $directory->facets('all');
        $directory->find('E002');
        $directory->findByLogin('ann');
        $directory->directReports('ann');
        $directory->syncRuns(20);
        $directory->totals();

        self::assertNotEmpty($connection->calls);
        foreach ($connection->calls as $call) {
            self::assertStringNotContainsString('EmployeeRestrictedDetails', $call['sql']);
        }
    }

    public function test_the_restricted_read_is_the_only_query_on_the_restricted_table_and_binds_the_code(): void
    {
        $connection = new FakeSqlConnection([[
            'PersonalEmail' => 'ann@example.test', 'Mobile' => null, 'Street' => null, 'Suburb' => null, 'State' => null,
            'Postcode' => null, 'DateOfBirth' => '1980-02-03', 'AnnualLeaveRemaining' => '12.50',
            'LongServiceLeaveRemaining' => null, 'SickLeaveRemaining' => '8.00', 'LeaveAsOf' => '2026-10-15',
        ]]);

        $restricted = (new AzureSqlEmployeeDirectory($connection))->restricted('E002');

        self::assertSame(12.5, $restricted['annualLeaveRemaining'] ?? null);
        self::assertSame('1980-02-03', $restricted['dateOfBirth'] ?? null);
        self::assertCount(1, $connection->calls);
        self::assertStringContainsString('[hr].[EmployeeRestrictedDetails]', $connection->calls[0]['sql']);
        self::assertSame([':code' => 'E002'], $connection->calls[0]['params']);
        self::assertNull((new AzureSqlEmployeeDirectory(new FakeSqlConnection([])))->restricted('E002'));
    }

    public function test_a_row_maps_to_the_item_shape_with_the_linked_user_lowercased(): void
    {
        $item = (new AzureSqlEmployeeDirectory(new FakeSqlConnection([self::ROW])))->find('E002');

        self::assertNotNull($item);
        self::assertTrue($item['isCurrent']);
        self::assertNull($item['managerName']);
        self::assertSame(['publicId' => 'aaaaaaaa-0000-4000-8000-000000000001', 'displayName' => 'Ann Lee'], $item['linkedUser']);
        self::assertNull((new AzureSqlEmployeeDirectory(new FakeSqlConnection([])))->find('NOPE'));
    }

    public function test_the_list_binds_every_filter_and_escapes_like_wildcards(): void
    {
        $connection = new FakeSqlConnection([]);

        (new AzureSqlEmployeeDirectory($connection))->search(new EmployeeListCriteria(
            page: 3, pageSize: 10, status: 'past', department: 'OpsExtru', factory: 'MELB', manager: 'ANN', search: 'o_b%',
            sortBy: 'dateHired', sortDirection: 'DESC',
        ));

        $sql = $connection->calls[0]['sql'];
        $params = $connection->calls[0]['params'];
        self::assertStringContainsString('e.[IsCurrent] = 0', $sql);
        self::assertStringContainsString('ORDER BY e.[DateHired] DESC, e.[EmployeeCode]', $sql);
        self::assertSame('OpsExtru', $params[':department']);
        self::assertSame('MELB', $params[':factory']);
        self::assertSame('ann', $params[':manager']);
        self::assertSame('%o[_]b[%]%', $params[':search0']);
        self::assertSame(20, $params[':offset']);
        self::assertSame(10, $params[':pageSize']);
        self::assertStringNotContainsString('o_b', $sql, 'the search term is bound, never inlined');

        (new AzureSqlEmployeeDirectory($connection))->search(new EmployeeListCriteria(sortBy: 'code'));
        self::assertStringContainsString('ORDER BY e.[EmployeeCode] ASC' . "\n", $connection->calls[2]['sql'] . "\n", 'no column twice in ORDER BY');
    }

    public function test_the_audit_row_holds_identifiers_and_the_route_only(): void
    {
        $connection = new FakeSqlConnection();

        (new AzureSqlRestrictedReadAudit($connection))->record(
            new ActorContext(7, 'pub', 'hr@tricab.com', 'req-1', '203.0.113.9', 'Brave'),
            'E002',
            'GET /api/v1/hr/employees/{code}',
        );

        self::assertCount(1, $connection->calls);
        self::assertStringContainsString('INSERT INTO [audit].[HrRestrictedReadEvents]', $connection->calls[0]['sql']);
        self::assertSame([
            ':actorUserId' => 7,
            ':employeeCode' => 'E002',
            ':endpoint' => 'GET /api/v1/hr/employees/{code}',
            ':permissionCode' => 'HR.EMPLOYEES.RESTRICTED.READ',
            ':correlationId' => 'req-1',
            ':ipAddress' => '203.0.113.9',
            ':userAgent' => 'Brave',
        ], $connection->calls[0]['params']);
    }
}
