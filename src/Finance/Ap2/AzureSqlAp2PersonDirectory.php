<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Ap2\Domain\Ap2Upstream;
use TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/** Who may own or approve, from `[identity]`: the Tri-AP approver query, asking for AP2 permissions. Read only. */
final class AzureSqlAp2PersonDirectory implements Ap2PersonDirectory
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
              AND UPPER(pe.[PermissionCode]) IN (:access, :wanted)
        WHERE (:one = 0 OR u.[UserId] = :user)
        GROUP BY u.[UserId], u.[DisplayName], u.[NormalizedEmailAddress]
        HAVING COUNT(DISTINCT UPPER(pe.[PermissionCode])) = :needed
        ORDER BY u.[DisplayName], u.[UserId]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function find(int $userId): ?Ap2Person
    {
        return $this->query(Module::PERMISSION_AP2_ACCESS, $userId)[0] ?? null;
    }

    public function holding(string $permission): array
    {
        return $this->query($permission, null);
    }

    /** @return list<Ap2Person> */
    private function query(string $permission, ?int $userId): array
    {
        $permission = strtoupper($permission);
        try {
            $rows = $this->connection->fetchAll(self::SQL, [
                'active' => UserStatus::ACTIVE,
                'access' => Module::PERMISSION_AP2_ACCESS,
                'wanted' => $permission,
                'one' => $userId === null ? 0 : 1,
                'user' => $userId ?? 0,
                'needed' => $permission === Module::PERMISSION_AP2_ACCESS ? 1 : 2,
            ]);
        } catch (DatabaseUnavailable $failure) {
            throw new Ap2Upstream('People could not be read from TriCab Finance', ['reason' => 'directory'], $failure);
        }

        return array_map(static fn (array $row): Ap2Person => new Ap2Person(
            AzureSqlValueMapper::intValue($row['UserId']),
            AzureSqlValueMapper::nullableString($row['DisplayName']) ?? '',
            strtolower(AzureSqlValueMapper::stringValue($row['email'])),
        ), $rows);
    }
}
