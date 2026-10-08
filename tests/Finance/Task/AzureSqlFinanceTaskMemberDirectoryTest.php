<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\AzureSqlFinanceTaskMemberDirectory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Core\Finance\Task\Domain\TaskMember;

/**
 * The member directory over `[identity]` and `finance.task_member_profiles`, against a real
 * local SQL Server with the finance and Tri-Task seeds applied ({@see LocalTaskIdentityServer});
 * skipped without one.
 */
#[Group('local-sql-server')]
final class AzureSqlFinanceTaskMemberDirectoryTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlFinanceTaskMemberDirectory $directory;

    protected function setUp(): void
    {
        $pdo = LocalTaskIdentityServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalTaskIdentityServer::reset($pdo);
        $this->directory = new AzureSqlFinanceTaskMemberDirectory(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
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
        return array_map(static fn (TaskMember $m): string => $m->name, $this->directory->members());
    }

    public function test_the_seeded_roles_make_members_with_the_highest_access_role(): void
    {
        $this->give($this->user('Una User', 'una@example.test'), 'FINANCE_TASK_USER');
        $this->give($this->user('Max Manager', 'max@example.test'), 'FINANCE_TASK_MANAGER');
        $this->give($this->user('Ada Admin', 'ada@example.test'), 'FINANCE_TASK_ADMIN');

        $members = $this->directory->members();

        self::assertSame(['Ada Admin', 'Max Manager', 'Una User'], array_map(static fn (TaskMember $m): string => $m->name, $members));
        self::assertSame(['admin', 'manager', 'user'], array_map(static fn (TaskMember $m): string => $m->accessRole, $members));
        self::assertSame('ada@example.test', $members[0]->email);
    }

    public function test_a_user_with_two_roles_is_shown_once_with_the_highest(): void
    {
        $id = $this->user('Two Roles', 'two@example.test');
        $this->give($id, 'FINANCE_TASK_USER');
        $this->give($id, 'FINANCE_TASK_MANAGER');

        $members = $this->directory->members();

        self::assertCount(1, $members);
        self::assertSame('manager', $members[0]->accessRole);
    }

    public function test_an_inactive_user_is_excluded_and_so_is_an_expired_or_withdrawn_role(): void
    {
        $this->give($this->user('Disabled Dan', 'dan@example.test', 'disabled'), 'FINANCE_TASK_USER');
        $this->give($this->user('Pending Pia', 'pia@example.test', 'pending'), 'FINANCE_TASK_USER');
        $this->give($this->user('Expired Eve', 'eve@example.test'), 'FINANCE_TASK_USER', true, '2020-01-01T00:00:00+00:00');
        $this->give($this->user('Withdrawn Will', 'will@example.test'), 'FINANCE_TASK_USER', false);
        $this->give($this->user('Active Amy', 'amy@example.test'), 'FINANCE_TASK_USER', true, '2099-01-01T00:00:00+00:00');

        self::assertSame(['Active Amy'], $this->names());
    }

    public function test_a_platform_administrator_or_finance_admin_alone_is_not_a_member(): void
    {
        $this->give($this->user('Plain Admin', 'plain@example.test'), 'FINANCE_ADMIN');
        $this->give($this->user('Nobody Nell', 'nell@example.test'), 'FINANCE_ADMIN');
        $this->give($this->user('Real Rita', 'rita@example.test'), 'FINANCE_TASK_USER');

        self::assertSame(['Real Rita'], $this->names());
    }

    public function test_a_role_withdrawn_from_the_catalogue_stops_a_person_being_a_member(): void
    {
        $this->give($this->user('Una User', 'una@example.test'), 'FINANCE_TASK_USER');
        $this->pdo->exec("UPDATE [identity].[Permissions] SET [IsActive] = 0 WHERE [PermissionCode] = N'FINANCE.TASK.TASKS.READ'");
        try {
            self::assertSame([], $this->names());
        } finally {
            $this->pdo->exec("UPDATE [identity].[Permissions] SET [IsActive] = 1 WHERE [PermissionCode] = N'FINANCE.TASK.TASKS.READ'");
        }
        self::assertSame(['Una User'], $this->names());
    }

    public function test_find_answers_one_member_or_null(): void
    {
        $id = $this->user('Una User', 'una@example.test');
        $this->give($id, 'FINANCE_TASK_USER');
        $stranger = $this->user('Stranger', 'stranger@example.test');

        self::assertSame('Una User', $this->directory->find($id)?->name);
        self::assertNull($this->directory->find($stranger));
        self::assertNull($this->directory->find(999999));
    }

    public function test_the_job_title_is_an_upsert_with_unicode_and_is_joined_to_the_member(): void
    {
        $id = $this->user('Una User', 'una@example.test');
        $this->give($id, 'FINANCE_TASK_USER');
        $at = new DateTimeImmutable('2026-10-07 01:02:03.456789', new DateTimeZone('UTC'));

        $this->directory->setJobTitle($id, 'Contrôleur financier', $at);
        $this->directory->setJobTitle($id, 'Group Accountant — Ünïcode', $at);

        self::assertSame('Group Accountant — Ünïcode', $this->directory->find($id)?->jobTitle);
        $count = $this->pdo->query('SELECT COUNT(*) FROM [finance].[task_member_profiles]');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(1, (int) $count->fetchColumn(), 'one profile per person');
    }
}
