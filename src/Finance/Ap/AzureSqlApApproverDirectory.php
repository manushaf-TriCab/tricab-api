<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Ap\Contract\ApApproverDirectory;
use TriCab\Core\Finance\Ap\Domain\ApApprover;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Who may complete an approval request, from `[identity]` (question Q10): an active user with an active, unexpired role that
 * holds an active `FINANCE.AP.APPROVALS.DECIDE` and an active `FINANCE.AP.ACCESS` (through any of their roles), once each however
 * many roles reach them. Read only, by platform user id; ordered on the display name (200 characters) and the id. The same
 * query as Tri-AR's assignee directory, asking for two permissions instead of one. A platform `ADMINISTRATOR` holding only
 * `FINANCE.ADMIN` is not one (decision D3); under D83 the Administrator role carries both permissions, so they are.
 */
final class AzureSqlApApproverDirectory implements ApApproverDirectory
{
    private const SQL = '
        SELECT u.[UserId], u.[DisplayName], u.[NormalizedEmailAddress] AS [email]
        FROM [identity].[Users] u
        INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[StatusCode] = :active
        INNER JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1
              AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
        INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
        INNER JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
        INNER JOIN [identity].[Permissions] pe ON pe.[PermissionId] = rp.[PermissionId] AND pe.[IsActive] = 1
              AND UPPER(pe.[PermissionCode]) IN (:access, :decide)
        GROUP BY u.[UserId], u.[DisplayName], u.[NormalizedEmailAddress]
        HAVING COUNT(DISTINCT UPPER(pe.[PermissionCode])) = 2
        ORDER BY u.[DisplayName], u.[UserId]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function approvers(): array
    {
        try {
            $rows = $this->connection->fetchAll(self::SQL, [
                'active' => UserStatus::ACTIVE,
                'access' => Module::PERMISSION_AP_ACCESS,
                'decide' => Module::PERMISSION_AP_APPROVALS_DECIDE,
            ]);
        } catch (DatabaseUnavailable $failure) {
            throw new ApUpstream('Approvers could not be read from TriCab Finance', 'directory', $failure);
        }

        return array_map(static fn (array $row): ApApprover => new ApApprover(
            AzureSqlValueMapper::intValue($row['UserId']),
            AzureSqlValueMapper::nullableString($row['DisplayName']) ?? '',
            strtolower(AzureSqlValueMapper::stringValue($row['email'])),
        ), $rows);
    }
}
