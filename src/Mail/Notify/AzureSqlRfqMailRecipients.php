<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Notify;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Rfq\Module;

/** Active users holding `RFQ.DRAFT.CREATE` through an active, unexpired role. Read only, from `[identity]`. */
final class AzureSqlRfqMailRecipients implements RfqMailRecipients
{
    private const SQL = '
        SELECT DISTINCT u.[UserId]
        FROM [identity].[Users] u
        INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[StatusCode] = :active
        INNER JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1
              AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
        INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
        INNER JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
        INNER JOIN [identity].[Permissions] pe ON pe.[PermissionId] = rp.[PermissionId] AND pe.[IsActive] = 1
              AND UPPER(pe.[PermissionCode]) = :wanted
        ORDER BY u.[UserId]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function userIds(): array
    {
        $rows = $this->connection->fetchAll(self::SQL, ['active' => UserStatus::ACTIVE, 'wanted' => Module::PERMISSION_DRAFT_CREATE]);

        return array_map(static fn (array $row): int => AzureSqlValueMapper::intValue($row['UserId']), $rows);
    }
}
