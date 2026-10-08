<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Tri-Task's members from `[identity]` (decision D20): an active user with an active,
 * unexpired role that holds an active `FINANCE.TASK.TASKS.READ`, joined with the
 * job title in `[finance].[task_member_profiles]`. Read only, except the job title.
 *
 * One row per person however many roles reach them. The highest of the three Tri-Task
 * roles held names the `accessRole`. Ordered on the display name (200 characters) and the
 * id, never on a long column.
 */
final class AzureSqlFinanceTaskMemberDirectory implements FinanceTaskMemberDirectory
{
    private const SELECT = '
        SELECT u.[UserId], u.[DisplayName], u.[NormalizedEmailAddress] AS [email],
               MAX(CASE UPPER(r.[RoleCode])
                       WHEN :adminRole THEN 3
                       WHEN :managerRole THEN 2
                       ELSE 1
                   END) AS [rank],
               p.[job_title] AS [job_title]
        FROM [identity].[Users] u
        INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[StatusCode] = :active
        INNER JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1
              AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
        INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
        INNER JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
        INNER JOIN [identity].[Permissions] pe ON pe.[PermissionId] = rp.[PermissionId] AND pe.[IsActive] = 1
              AND UPPER(pe.[PermissionCode]) = :permission
        LEFT JOIN [finance].[task_member_profiles] p ON p.[user_id] = u.[UserId]';

    private const GROUP = ' GROUP BY u.[UserId], u.[DisplayName], u.[NormalizedEmailAddress], p.[job_title]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function members(): array
    {
        $rows = $this->connection->fetchAll(
            self::SELECT . self::GROUP . ' ORDER BY u.[DisplayName], u.[UserId]',
            $this->params(),
        );

        return array_map(self::member(...), $rows);
    }

    public function find(int $userId): ?TaskMember
    {
        $rows = $this->connection->fetchAll(
            self::SELECT . ' WHERE u.[UserId] = :userId' . self::GROUP,
            $this->params() + ['userId' => $userId],
        );

        return $rows === [] ? null : self::member($rows[0]);
    }

    public function setJobTitle(int $userId, string $jobTitle, DateTimeImmutable $now): void
    {
        $at = AzureSqlValueMapper::formatDateTime($now);
        // Update first; insert if nothing matched. The unique index on user_id settles a race.
        $existing = $this->connection->fetchAll(
            'SELECT [id] FROM [finance].[task_member_profiles] WHERE [user_id] = :userId',
            ['userId' => $userId],
        );
        if ($existing !== []) {
            $this->connection->execute(
                'UPDATE [finance].[task_member_profiles] SET [job_title] = :title, [updated_date] = :at WHERE [user_id] = :userId',
                ['title' => $jobTitle, 'at' => $at, 'userId' => $userId],
            );

            return;
        }
        $this->connection->execute(
            'INSERT INTO [finance].[task_member_profiles] ([id], [user_id], [job_title], [created_date], [updated_date])
             VALUES (:id, :userId, :title, :created, :updated)',
            ['id' => bin2hex(random_bytes(16)), 'userId' => $userId, 'title' => $jobTitle, 'created' => $at, 'updated' => $at],
        );
    }

    /** @return array<string, string> */
    private function params(): array
    {
        return [
            'adminRole' => Module::ROLE_TASK_ADMIN,
            'managerRole' => Module::ROLE_TASK_MANAGER,
            'active' => UserStatus::ACTIVE,
            'permission' => Module::PERMISSION_TASK_TASKS_READ,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function member(array $row): TaskMember
    {
        $rank = AzureSqlValueMapper::intValue($row['rank']);

        return new TaskMember(
            AzureSqlValueMapper::intValue($row['UserId']),
            AzureSqlValueMapper::nullableString($row['DisplayName']) ?? '',
            strtolower(AzureSqlValueMapper::stringValue($row['email'])),
            $rank >= 3 ? TaskMember::ROLE_ADMIN : ($rank === 2 ? TaskMember::ROLE_MANAGER : TaskMember::ROLE_USER),
            AzureSqlValueMapper::nullableString($row['job_title']) ?? '',
        );
    }
}
