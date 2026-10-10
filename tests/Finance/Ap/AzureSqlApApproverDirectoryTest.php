<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap\AzureSqlApApproverDirectory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Core\Finance\Ap\Domain\ApApprover;

/**
 * The approver directory over `[identity]`, against a real local SQL Server with the finance and Tri-AP seeds applied
 * ({@see LocalApIdentityServer}, a database of its own); skipped without one. Approvers are the active users holding
 * `FINANCE.AP.ACCESS` and `FINANCE.AP.APPROVALS.DECIDE` through active roles (question Q10), each once, read by platform user id.
 */
#[Group('local-sql-server')]
final class AzureSqlApApproverDirectoryTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlApApproverDirectory $directory;

    protected function setUp(): void
    {
        $pdo = LocalApIdentityServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalApIdentityServer::reset($pdo);
        $this->directory = new AzureSqlApApproverDirectory(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
    }

    private function user(string $name, string $email, string $status = 'active'): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
             OUTPUT INSERTED.[UserId]
             SELECT :email, :normalized, :name, [UserStatusId] FROM [identity].[UserStatuses] WHERE [StatusCode] = :status',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['email' => $email, 'normalized' => strtolower($email), 'name' => $name, 'status' => $status]);

        return (int) $statement->fetchColumn();
    }

    private function give(int $userId, string $roleCode, bool $active = true, ?string $expires = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [identity].[UserRoles] ([UserId], [RoleId], [IsActive], [ExpiresAt])
             SELECT :userId, [RoleId], :active, :expires FROM [identity].[Roles] WHERE [RoleCode] = :role',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['userId' => $userId, 'role' => $roleCode, 'active' => $active ? 1 : 0, 'expires' => $expires]);
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM [identity].[UserRoles] ur JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] WHERE ur.[UserId] = :userId AND r.[RoleCode] = :role');
        self::assertInstanceOf(PDOStatement::class, $check);
        $check->execute(['userId' => $userId, 'role' => $roleCode]);
        self::assertSame(1, (int) $check->fetchColumn(), 'The role ' . $roleCode . ' is seeded and was given.');
    }

    /** A role of the test's own holding only these permissions; the roles it makes go away with the next reset. */
    private function role(string $code, string ...$permissions): void
    {
        $this->pdo->exec("DELETE rp FROM [identity].[RolePermissions] rp JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId] WHERE r.[RoleCode] = N'" . $code . "'");
        $this->pdo->exec("DELETE FROM [identity].[Roles] WHERE [RoleCode] = N'" . $code . "'");
        $this->pdo->exec("INSERT INTO [identity].[Roles] ([RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive]) VALUES (N'" . $code . "', N'" . $code . "', N'test', 0, 1)");
        foreach ($permissions as $permission) {
            $this->pdo->exec(
                "INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
                 SELECT r.[RoleId], p.[PermissionId], 1 FROM [identity].[Roles] r, [identity].[Permissions] p
                  WHERE r.[RoleCode] = N'" . $code . "' AND p.[PermissionCode] = N'" . $permission . "'",
            );
        }
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(static fn (ApApprover $a): string => $a->name, $this->directory->approvers());
    }

    public function test_approvers_and_administrators_can_complete_a_request_but_preparers_and_operators_cannot(): void
    {
        $this->give($this->user('Pia Preparer', 'pia@example.test'), 'FINANCE_AP_PREPARER');
        $this->give($this->user('Olive Operator', 'olive@example.test'), 'FINANCE_AP_OPERATOR');
        $this->give($this->user('Ann Approver', 'Ann@Example.test'), 'FINANCE_AP_APPROVER');
        $this->give($this->user('Ada Admin', 'ada@example.test'), 'FINANCE_AP_ADMIN');

        $approvers = $this->directory->approvers();

        self::assertSame(['Ada Admin', 'Ann Approver'], array_map(static fn (ApApprover $a): string => $a->name, $approvers));
        self::assertSame('ann@example.test', $approvers[1]->email, 'the address is lower-case');
        self::assertGreaterThan(0, $approvers[0]->userId);
    }

    public function test_a_user_with_two_roles_is_listed_once(): void
    {
        $id = $this->user('Two Roles', 'two@example.test');
        $this->give($id, 'FINANCE_AP_APPROVER');
        $this->give($id, 'FINANCE_AP_ADMIN');

        self::assertSame(['Two Roles'], $this->names());
    }

    public function test_an_inactive_user_an_expired_role_and_a_withdrawn_role_are_excluded(): void
    {
        $this->give($this->user('Disabled Dan', 'dan@example.test', 'disabled'), 'FINANCE_AP_APPROVER');
        $this->give($this->user('Pending Pia', 'pia@example.test', 'pending'), 'FINANCE_AP_APPROVER');
        $this->give($this->user('Expired Eve', 'eve@example.test'), 'FINANCE_AP_APPROVER', true, '2020-01-01T00:00:00+00:00');
        $this->give($this->user('Withdrawn Will', 'will@example.test'), 'FINANCE_AP_APPROVER', false);
        $this->give($this->user('Active Amy', 'amy@example.test'), 'FINANCE_AP_APPROVER', true, '2099-01-01T00:00:00+00:00');

        self::assertSame(['Active Amy'], $this->names());
    }

    public function test_a_finance_administrator_alone_is_not_an_approver(): void
    {
        $this->give($this->user('Finance Admin', 'fa@example.test'), 'FINANCE_ADMIN');
        $this->give($this->user('Real Rita', 'rita@example.test'), 'FINANCE_AP_APPROVER');

        self::assertSame(['Real Rita'], $this->names());
    }

    public function test_the_permission_to_decide_needs_the_app_access_but_they_may_come_from_different_roles(): void
    {
        $this->role('AP_TEST_DECIDE_ONLY', 'FINANCE.AP.APPROVALS.DECIDE');
        $this->role('AP_TEST_ACCESS_ONLY', 'FINANCE.AP.ACCESS');
        $this->give($this->user('Decide Only', 'do@example.test'), 'AP_TEST_DECIDE_ONLY');
        $both = $this->user('Split Roles', 'split@example.test');
        $this->give($both, 'AP_TEST_DECIDE_ONLY');
        $this->give($both, 'AP_TEST_ACCESS_ONLY');
        $this->give($this->user('Access Only', 'ao@example.test'), 'AP_TEST_ACCESS_ONLY');

        self::assertSame(['Split Roles'], $this->names());
    }

    public function test_a_withdrawn_permission_stops_a_person_being_an_approver(): void
    {
        $this->give($this->user('Ann Approver', 'ann@example.test'), 'FINANCE_AP_APPROVER');
        $this->pdo->exec("UPDATE [identity].[Permissions] SET [IsActive] = 0 WHERE [PermissionCode] = N'FINANCE.AP.APPROVALS.DECIDE'");
        try {
            self::assertSame([], $this->names());
        } finally {
            $this->pdo->exec("UPDATE [identity].[Permissions] SET [IsActive] = 1 WHERE [PermissionCode] = N'FINANCE.AP.APPROVALS.DECIDE'");
        }
        self::assertSame(['Ann Approver'], $this->names());
    }

    public function test_the_order_is_by_name_then_id_and_a_unicode_name_round_trips(): void
    {
        $b = $this->user('Zoë Łukasz 北京', 'zoe@example.test');
        $a1 = $this->user('Same Name', 'same1@example.test');
        $a2 = $this->user('Same Name', 'same2@example.test');
        foreach ([$b, $a2, $a1] as $id) {
            $this->give($id, 'FINANCE_AP_APPROVER');
        }

        $approvers = $this->directory->approvers();

        self::assertSame(['Same Name', 'Same Name', 'Zoë Łukasz 北京'], array_map(static fn (ApApprover $a): string => $a->name, $approvers));
        self::assertSame([$a1, $a2, $b], array_map(static fn (ApApprover $a): int => $a->userId, $approvers));
    }

    public function test_nobody_holding_the_role_is_an_empty_list(): void
    {
        self::assertSame([], $this->directory->approvers());
    }
}
