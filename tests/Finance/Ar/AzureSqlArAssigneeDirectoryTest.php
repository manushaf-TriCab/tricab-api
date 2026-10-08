<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ar\AzureSqlArAssigneeDirectory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Core\Finance\Ar\Domain\ArAssignee;

/**
 * The assignee directory over `[identity]`, against a real local SQL Server with the finance and
 * Tri-AR seeds applied ({@see LocalArIdentityServer}); skipped without one. Assignees are the
 * active users holding `FINANCE.AR.RECORDS.WRITE` through an active role (decision D43).
 */
#[Group('local-sql-server')]
final class AzureSqlArAssigneeDirectoryTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlArAssigneeDirectory $directory;

    protected function setUp(): void
    {
        $pdo = LocalArIdentityServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalArIdentityServer::reset($pdo);
        $this->directory = new AzureSqlArAssigneeDirectory(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
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

    /** @return list<string> */
    private function names(): array
    {
        return array_map(static fn (ArAssignee $a): string => $a->name, $this->directory->assignees());
    }

    public function test_operators_and_administrators_can_be_assigned_to_but_reviewers_cannot(): void
    {
        $this->give($this->user('Rex Reviewer', 'rex@example.test'), 'FINANCE_AR_REVIEWER');
        $this->give($this->user('Olive Operator', 'olive@example.test'), 'FINANCE_AR_OPERATOR');
        $this->give($this->user('Ada Admin', 'ada@example.test'), 'FINANCE_AR_ADMIN');

        $assignees = $this->directory->assignees();

        self::assertSame(['Ada Admin', 'Olive Operator'], array_map(static fn (ArAssignee $a): string => $a->name, $assignees));
        self::assertSame('ada@example.test', $assignees[0]->email);
    }

    public function test_a_user_with_two_roles_is_listed_once(): void
    {
        $id = $this->user('Two Roles', 'two@example.test');
        $this->give($id, 'FINANCE_AR_OPERATOR');
        $this->give($id, 'FINANCE_AR_ADMIN');

        self::assertSame(['Two Roles'], $this->names());
    }

    public function test_an_inactive_user_an_expired_role_and_a_withdrawn_role_are_excluded(): void
    {
        $this->give($this->user('Disabled Dan', 'dan@example.test', 'disabled'), 'FINANCE_AR_OPERATOR');
        $this->give($this->user('Pending Pia', 'pia@example.test', 'pending'), 'FINANCE_AR_OPERATOR');
        $this->give($this->user('Expired Eve', 'eve@example.test'), 'FINANCE_AR_OPERATOR', true, '2020-01-01T00:00:00+00:00');
        $this->give($this->user('Withdrawn Will', 'will@example.test'), 'FINANCE_AR_OPERATOR', false);
        $this->give($this->user('Active Amy', 'amy@example.test'), 'FINANCE_AR_OPERATOR', true, '2099-01-01T00:00:00+00:00');

        self::assertSame(['Active Amy'], $this->names());
    }

    public function test_a_platform_or_finance_administrator_alone_is_not_an_assignee(): void
    {
        $this->give($this->user('Finance Admin', 'fa@example.test'), 'FINANCE_ADMIN');
        $this->give($this->user('Real Rita', 'rita@example.test'), 'FINANCE_AR_OPERATOR');

        self::assertSame(['Real Rita'], $this->names());
    }

    public function test_a_withdrawn_permission_stops_a_person_being_an_assignee(): void
    {
        $this->give($this->user('Olive Operator', 'olive@example.test'), 'FINANCE_AR_OPERATOR');
        $this->pdo->exec("UPDATE [identity].[Permissions] SET [IsActive] = 0 WHERE [PermissionCode] = N'FINANCE.AR.RECORDS.WRITE'");
        try {
            self::assertSame([], $this->names());
        } finally {
            $this->pdo->exec("UPDATE [identity].[Permissions] SET [IsActive] = 1 WHERE [PermissionCode] = N'FINANCE.AR.RECORDS.WRITE'");
        }
        self::assertSame(['Olive Operator'], $this->names());
    }

    public function test_find_answers_an_assignee_or_nothing_and_a_unicode_name_round_trips(): void
    {
        $id = $this->user('Zoë Łukasz 北京', 'zoe@example.test');
        $this->give($id, 'FINANCE_AR_OPERATOR');
        $reviewer = $this->user('Rex Reviewer', 'rex@example.test');
        $this->give($reviewer, 'FINANCE_AR_REVIEWER');

        $found = $this->directory->find($id);

        self::assertInstanceOf(ArAssignee::class, $found);
        self::assertSame('Zoë Łukasz 北京', $found->name);
        self::assertSame($id, $found->userId);
        self::assertNull($this->directory->find($reviewer));
        self::assertNull($this->directory->find(999999));
    }
}
