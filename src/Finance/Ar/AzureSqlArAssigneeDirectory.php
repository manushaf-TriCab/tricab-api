<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory;
use TriCab\Core\Finance\Ar\Domain\ArAssignee;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Who a Tri-AR record can be assigned to, from `[identity]` (decision D43): an active user with
 * an active, unexpired role that holds an active `FINANCE.AR.RECORDS.WRITE`. Read only. One row
 * per person however many roles reach them, ordered on the display name (200 characters) and the
 * id, never on a long column. The same query as Tri-Task's member directory, without the profile.
 */
final class AzureSqlArAssigneeDirectory implements ArAssigneeDirectory
{
    private const SELECT = '
        SELECT u.[UserId], u.[DisplayName], u.[NormalizedEmailAddress] AS [email]
        FROM [identity].[Users] u
        INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[StatusCode] = :active
        INNER JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1
              AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
        INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
        INNER JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
        INNER JOIN [identity].[Permissions] pe ON pe.[PermissionId] = rp.[PermissionId] AND pe.[IsActive] = 1
              AND UPPER(pe.[PermissionCode]) = :permission';

    private const GROUP = ' GROUP BY u.[UserId], u.[DisplayName], u.[NormalizedEmailAddress]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function assignees(): array
    {
        $rows = $this->connection->fetchAll(
            self::SELECT . self::GROUP . ' ORDER BY u.[DisplayName], u.[UserId]',
            $this->params(),
        );

        return array_map(self::assignee(...), $rows);
    }

    public function find(int $userId): ?ArAssignee
    {
        $rows = $this->connection->fetchAll(
            self::SELECT . ' WHERE u.[UserId] = :userId' . self::GROUP,
            $this->params() + ['userId' => $userId],
        );

        return $rows === [] ? null : self::assignee($rows[0]);
    }

    /** @return array<string, string> */
    private function params(): array
    {
        return ['active' => UserStatus::ACTIVE, 'permission' => Module::PERMISSION_AR_RECORDS_WRITE];
    }

    /** @param array<string, mixed> $row */
    private static function assignee(array $row): ArAssignee
    {
        return new ArAssignee(
            AzureSqlValueMapper::intValue($row['UserId']),
            AzureSqlValueMapper::nullableString($row['DisplayName']) ?? '',
            strtolower(AzureSqlValueMapper::stringValue($row['email'])),
        );
    }
}
