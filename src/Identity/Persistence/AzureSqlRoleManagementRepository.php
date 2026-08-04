<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleDetails;
use TriCab\Core\Identity\Application\DTO\UserManagement\RolePermissionAssignment;
use TriCab\Core\Identity\Application\DTO\UserManagement\RoleSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserSummary;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Azure SQL implementation of the Core RoleManagementRepository contract.
 * All role lookups compare RoleCode via UPPER() to stay case-insensitive
 * while remaining a bound parameter (never a raw interpolated identifier).
 */
final class AzureSqlRoleManagementRepository implements RoleManagementRepository
{
    use AzureSqlTransaction;
    use AzureSqlUserSummaryHydration;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly UuidV4Generator $uuidGenerator = new UuidV4Generator(),
    ) {
    }

    public function listRoles(?string $search = null, ?bool $isActive = null): array
    {
        $conditions = [];
        $params = [];

        $normalizedSearch = $search !== null ? trim($search) : null;
        if ($normalizedSearch !== null && $normalizedSearch !== '') {
            $conditions[] = '(r.[RoleCode] LIKE :search OR r.[RoleName] LIKE :search)';
            $params[':search'] = '%' . $normalizedSearch . '%';
        }

        if ($isActive !== null) {
            $conditions[] = 'r.[IsActive] = :isActive';
            $params[':isActive'] = $isActive ? 1 : 0;
        }

        $where = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [PublicId],
    r.[RoleCode],
    r.[RoleName],
    r.[Description],
    r.[IsSystemRole],
    r.[IsActive],
    CONVERT(VARCHAR(33), r.[CreatedAt], 127) AS [CreatedAt],
    CONVERT(VARCHAR(33), r.[UpdatedAt], 127) AS [UpdatedAt],
    (
        SELECT COUNT(DISTINCT ur.[UserId])
        FROM [identity].[UserRoles] ur
        WHERE ur.[RoleId] = r.[RoleId]
          AND ur.[IsActive] = 1
          AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
    ) AS [UserCount],
    (
        SELECT COUNT(*)
        FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = r.[RoleId]
          AND rp.[IsActive] = 1
    ) AS [PermissionCount]
FROM [identity].[Roles] r
WHERE 1 = 1{$where}
ORDER BY r.[RoleCode]
SQL,
            $params,
        );

        return array_map(fn (array $row): RoleSummary => $this->hydrateRoleSummary($row), $rows);
    }

    public function findByCode(RoleCode $roleCode): ?RoleDetails
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    r.[RoleId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [PublicId],
    r.[RoleCode],
    r.[RoleName],
    r.[Description],
    r.[IsSystemRole],
    r.[IsActive],
    CONVERT(VARCHAR(33), r.[CreatedAt], 127) AS [CreatedAt],
    CONVERT(VARCHAR(33), r.[UpdatedAt], 127) AS [UpdatedAt]
FROM [identity].[Roles] r
WHERE UPPER(r.[RoleCode]) = :roleCode
SQL,
            [':roleCode' => $roleCode->toString()],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $roleId = AzureSqlValueMapper::intValue($row['RoleId'] ?? null);

        return new RoleDetails(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            roleCode: AzureSqlValueMapper::stringValue($row['RoleCode'] ?? null),
            roleName: AzureSqlValueMapper::stringValue($row['RoleName'] ?? null),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            isSystemRole: AzureSqlValueMapper::boolValue($row['IsSystemRole'] ?? false),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            permissions: $this->fetchRolePermissionAssignments($roleId),
            assignedUsers: $this->fetchUsersAssignedToRole($roleId),
            createdAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['CreatedAt'] ?? null, 'CreatedAt'),
            ),
            updatedAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['UpdatedAt'] ?? null, 'UpdatedAt'),
            ),
        );
    }

    public function findRoleIdByCode(RoleCode $roleCode): ?int
    {
        return $this->roleIdByCode($roleCode);
    }

    public function roleCodeExists(RoleCode $roleCode): bool
    {
        return $this->roleIdByCode($roleCode) !== null;
    }

    public function isRoleActive(RoleCode $roleCode): bool
    {
        $rows = $this->connection->fetchAll(
            'SELECT [IsActive] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = :roleCode',
            [':roleCode' => $roleCode->toString()],
        );

        if ($rows === []) {
            return false;
        }

        return AzureSqlValueMapper::boolValue($rows[0]['IsActive'] ?? false);
    }

    public function createRole(
        RoleCode $roleCode,
        string $roleName,
        ?string $description,
        bool $isActive,
        UserId $actorUserId,
    ): RoleDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($roleCode, $roleName, $description, $isActive): RoleDetails {
                $publicId = $this->uuidGenerator->generate()->toString();
                $isSystemRole = ProtectedIdentityCatalog::isSystemProtectedRole($roleCode);

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [identity].[Roles] (
    [PublicId],
    [RoleCode],
    [RoleName],
    [Description],
    [IsSystemRole],
    [IsActive]
)
OUTPUT INSERTED.[RoleId]
VALUES (
    :publicId,
    :roleCode,
    :roleName,
    :description,
    :isSystemRole,
    :isActive
)
SQL,
                    [
                        ':publicId' => $publicId,
                        ':roleCode' => $roleCode->toString(),
                        ':roleName' => $roleName,
                        ':description' => $description,
                        ':isSystemRole' => $isSystemRole ? 1 : 0,
                        ':isActive' => $isActive ? 1 : 0,
                    ],
                );

                if ($rows === []) {
                    throw IdentityPersistenceException::insertFailed('Role');
                }

                $details = $this->findByCode($roleCode);
                if ($details === null) {
                    throw IdentityPersistenceException::insertFailed('Role');
                }

                return $details;
            },
        );
    }

    public function updateRole(
        RoleCode $roleCode,
        string $roleName,
        ?string $description,
        UserId $actorUserId,
    ): RoleDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($roleCode, $roleName, $description): RoleDetails {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [identity].[Roles]
SET
    [RoleName] = :roleName,
    [Description] = :description,
    [UpdatedAt] = SYSDATETIMEOFFSET()
OUTPUT INSERTED.[RoleId]
WHERE UPPER([RoleCode]) = :roleCode
SQL,
                    [
                        ':roleName' => $roleName,
                        ':description' => $description,
                        ':roleCode' => $roleCode->toString(),
                    ],
                );

                if ($rows === []) {
                    throw IdentityPersistenceException::roleNotFound($roleCode->toString());
                }

                $details = $this->findByCode($roleCode);
                if ($details === null) {
                    throw IdentityPersistenceException::roleNotFound($roleCode->toString());
                }

                return $details;
            },
        );
    }

    public function changeStatus(
        RoleCode $roleCode,
        bool $isActive,
        UserId $actorUserId,
    ): RoleDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($roleCode, $isActive): RoleDetails {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [identity].[Roles]
SET
    [IsActive] = :isActive,
    [UpdatedAt] = SYSDATETIMEOFFSET()
OUTPUT INSERTED.[RoleId]
WHERE UPPER([RoleCode]) = :roleCode
SQL,
                    [':isActive' => $isActive ? 1 : 0, ':roleCode' => $roleCode->toString()],
                );

                if ($rows === []) {
                    throw IdentityPersistenceException::roleNotFound($roleCode->toString());
                }

                $details = $this->findByCode($roleCode);
                if ($details === null) {
                    throw IdentityPersistenceException::roleNotFound($roleCode->toString());
                }

                return $details;
            },
        );
    }

    public function assignPermission(
        RoleCode $roleCode,
        PermissionCode $permissionCode,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($roleCode, $permissionCode): void {
                $roleId = $this->requireRoleIdByCode($roleCode);
                $permissionId = $this->requirePermissionIdByCode($permissionCode);

                $existingRows = $this->connection->fetchAll(
                    <<<'SQL'
SELECT [RolePermissionId], [IsActive]
FROM [identity].[RolePermissions]
WHERE [RoleId] = :roleId AND [PermissionId] = :permissionId
SQL,
                    [':roleId' => $roleId, ':permissionId' => $permissionId],
                );

                if ($existingRows === []) {
                    $this->connection->execute(
                        <<<'SQL'
INSERT INTO [identity].[RolePermissions] (
    [RoleId],
    [PermissionId],
    [IsActive]
)
VALUES (
    :roleId,
    :permissionId,
    1
)
SQL,
                        [':roleId' => $roleId, ':permissionId' => $permissionId],
                    );
                    $this->touchRole($roleId);

                    return;
                }

                if (AzureSqlValueMapper::boolValue($existingRows[0]['IsActive'] ?? false)) {
                    return;
                }

                $this->connection->execute(
                    <<<'SQL'
UPDATE [identity].[RolePermissions]
SET
    [IsActive] = 1,
    [AssignedAt] = SYSDATETIMEOFFSET()
WHERE [RoleId] = :roleId AND [PermissionId] = :permissionId
SQL,
                    [':roleId' => $roleId, ':permissionId' => $permissionId],
                );
                $this->touchRole($roleId);
            },
        );
    }

    public function removePermission(RoleCode $roleCode, PermissionCode $permissionCode): bool
    {
        return $this->runInTransaction(
            $this->connection,
            function () use ($roleCode, $permissionCode): bool {
                $roleId = $this->roleIdByCode($roleCode);
                if ($roleId === null) {
                    return false;
                }

                $permissionId = $this->permissionIdByCode($permissionCode);
                if ($permissionId === null) {
                    return false;
                }

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [identity].[RolePermissions]
SET [IsActive] = 0
OUTPUT INSERTED.[RolePermissionId]
WHERE [RoleId] = :roleId AND [PermissionId] = :permissionId AND [IsActive] = 1
SQL,
                    [':roleId' => $roleId, ':permissionId' => $permissionId],
                );

                if ($rows === []) {
                    return false;
                }

                $this->touchRole($roleId);

                return true;
            },
        );
    }

    public function roleHasPermission(RoleCode $roleCode, PermissionCode $permissionCode): bool
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1 1 AS [Found]
FROM [identity].[RolePermissions] rp
INNER JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
INNER JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
WHERE UPPER(r.[RoleCode]) = :roleCode
  AND UPPER(p.[PermissionCode]) = :permissionCode
  AND rp.[IsActive] = 1
SQL,
            [':roleCode' => $roleCode->toString(), ':permissionCode' => $permissionCode->toString()],
        );

        return $rows !== [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRoleSummary(array $row): RoleSummary
    {
        return new RoleSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            roleCode: AzureSqlValueMapper::stringValue($row['RoleCode'] ?? null),
            roleName: AzureSqlValueMapper::stringValue($row['RoleName'] ?? null),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            isSystemRole: AzureSqlValueMapper::boolValue($row['IsSystemRole'] ?? false),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            userCount: AzureSqlValueMapper::intValue($row['UserCount'] ?? 0),
            permissionCount: AzureSqlValueMapper::intValue($row['PermissionCount'] ?? 0),
            createdAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['CreatedAt'] ?? null, 'CreatedAt'),
            ),
            updatedAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['UpdatedAt'] ?? null, 'UpdatedAt'),
            ),
        );
    }

    /**
     * @return list<RolePermissionAssignment>
     */
    private function fetchRolePermissionAssignments(int $roleId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    p.[PermissionCode],
    p.[PermissionName],
    p.[ModuleCode],
    p.[IsActive],
    CONVERT(VARCHAR(33), rp.[AssignedAt], 127) AS [AssignedAt]
FROM [identity].[RolePermissions] rp
INNER JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
WHERE rp.[RoleId] = :roleId
  AND rp.[IsActive] = 1
ORDER BY p.[PermissionCode]
SQL,
            [':roleId' => $roleId],
        );

        return array_map(
            static fn (array $row): RolePermissionAssignment => new RolePermissionAssignment(
                permissionCode: AzureSqlValueMapper::stringValue($row['PermissionCode'] ?? null),
                permissionName: AzureSqlValueMapper::stringValue($row['PermissionName'] ?? null),
                moduleCode: AzureSqlValueMapper::stringValue($row['ModuleCode'] ?? null),
                isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
                assignedAtUtc: AzureSqlValueMapper::formatIso8601(
                    AzureSqlValueMapper::requireDateTime($row['AssignedAt'] ?? null, 'AssignedAt'),
                ),
            ),
            $rows,
        );
    }

    /**
     * @return list<UserSummary>
     */
    private function fetchUsersAssignedToRole(int $roleId): array
    {
        $rows = $this->connection->fetchAll(
            self::USER_SUMMARY_SELECT_SQL . <<<'SQL'

WHERE EXISTS (
    SELECT 1
    FROM [identity].[UserRoles] ura
    WHERE ura.[UserId] = u.[UserId]
      AND ura.[RoleId] = :roleId
      AND ura.[IsActive] = 1
      AND (ura.[ExpiresAt] IS NULL OR ura.[ExpiresAt] > SYSDATETIMEOFFSET())
)
ORDER BY u.[EmailAddress]
SQL,
            [':roleId' => $roleId],
        );

        return array_map(fn (array $row): UserSummary => $this->hydrateUserSummary($row), $rows);
    }

    private function roleIdByCode(RoleCode $roleCode): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [RoleId] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = :roleCode',
            [':roleCode' => $roleCode->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return AzureSqlValueMapper::intValue($rows[0]['RoleId'] ?? null);
    }

    private function requireRoleIdByCode(RoleCode $roleCode): int
    {
        $roleId = $this->roleIdByCode($roleCode);
        if ($roleId === null) {
            throw IdentityPersistenceException::roleNotFound($roleCode->toString());
        }

        return $roleId;
    }

    private function permissionIdByCode(PermissionCode $permissionCode): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [PermissionId] FROM [identity].[Permissions] WHERE UPPER([PermissionCode]) = :permissionCode',
            [':permissionCode' => $permissionCode->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return AzureSqlValueMapper::intValue($rows[0]['PermissionId'] ?? null);
    }

    private function requirePermissionIdByCode(PermissionCode $permissionCode): int
    {
        $permissionId = $this->permissionIdByCode($permissionCode);
        if ($permissionId === null) {
            throw IdentityPersistenceException::permissionNotFound($permissionCode->toString());
        }

        return $permissionId;
    }

    private function touchRole(int $roleId): void
    {
        $this->connection->execute(
            'UPDATE [identity].[Roles] SET [UpdatedAt] = SYSDATETIMEOFFSET() WHERE [RoleId] = :roleId',
            [':roleId' => $roleId],
        );
    }
}
