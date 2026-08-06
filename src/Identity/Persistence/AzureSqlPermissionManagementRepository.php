<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\PermissionSummary;
use TriCab\Core\Identity\Contract\PermissionManagementRepository;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Azure SQL implementation of the Core PermissionManagementRepository contract.
 */
final class AzureSqlPermissionManagementRepository implements PermissionManagementRepository
{
    use AzureSqlTransaction;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly UuidV4Generator $uuidGenerator = new UuidV4Generator(),
    ) {
    }

    public function listPermissions(?string $search = null, ?bool $isActive = null, ?string $moduleCode = null): array
    {
        $conditions = [];
        $params = [];

        $normalizedSearch = $search !== null ? trim($search) : null;
        if ($normalizedSearch !== null && $normalizedSearch !== '') {
            $conditions[] = '(p.[PermissionCode] LIKE :search OR p.[PermissionName] LIKE :search)';
            $params[':search'] = '%' . $normalizedSearch . '%';
        }

        if ($isActive !== null) {
            $conditions[] = 'p.[IsActive] = :isActive';
            $params[':isActive'] = $isActive ? 1 : 0;
        }

        if ($moduleCode !== null && trim($moduleCode) !== '') {
            $conditions[] = 'p.[ModuleCode] = :moduleCode';
            $params[':moduleCode'] = trim($moduleCode);
        }

        $where = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[PermissionCode],
    p.[PermissionName],
    p.[Description],
    p.[ModuleCode],
    p.[IsActive],
    CONVERT(VARCHAR(33), p.[CreatedAt], 127) AS [CreatedAt],
    CONVERT(VARCHAR(33), p.[UpdatedAt], 127) AS [UpdatedAt],
    (
        SELECT STRING_AGG(r.[RoleCode], ',')
        FROM [identity].[RolePermissions] rp
        INNER JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        WHERE rp.[PermissionId] = p.[PermissionId]
          AND rp.[IsActive] = 1
    ) AS [RoleCodes]
FROM [identity].[Permissions] p
WHERE 1 = 1{$where}
ORDER BY p.[PermissionCode]
SQL,
            $params,
        );

        return array_map(fn (array $row): PermissionSummary => $this->hydratePermissionSummary($row), $rows);
    }

    public function findByCode(PermissionCode $permissionCode): ?PermissionSummary
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[PermissionCode],
    p.[PermissionName],
    p.[Description],
    p.[ModuleCode],
    p.[IsActive],
    CONVERT(VARCHAR(33), p.[CreatedAt], 127) AS [CreatedAt],
    CONVERT(VARCHAR(33), p.[UpdatedAt], 127) AS [UpdatedAt],
    (
        SELECT STRING_AGG(r.[RoleCode], ',')
        FROM [identity].[RolePermissions] rp
        INNER JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        WHERE rp.[PermissionId] = p.[PermissionId]
          AND rp.[IsActive] = 1
    ) AS [RoleCodes]
FROM [identity].[Permissions] p
WHERE UPPER(p.[PermissionCode]) = :permissionCode
SQL,
            [':permissionCode' => $permissionCode->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return $this->hydratePermissionSummary($rows[0]);
    }

    public function findPermissionIdByCode(PermissionCode $permissionCode): ?int
    {
        return $this->permissionIdByCode($permissionCode);
    }

    public function permissionCodeExists(PermissionCode $permissionCode): bool
    {
        return $this->permissionIdByCode($permissionCode) !== null;
    }

    public function isPermissionActive(PermissionCode $permissionCode): bool
    {
        $rows = $this->connection->fetchAll(
            'SELECT [IsActive] FROM [identity].[Permissions] WHERE UPPER([PermissionCode]) = :permissionCode',
            [':permissionCode' => $permissionCode->toString()],
        );

        if ($rows === []) {
            return false;
        }

        return AzureSqlValueMapper::boolValue($rows[0]['IsActive'] ?? false);
    }

    public function countActiveRolesUsing(PermissionCode $permissionCode): int
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT COUNT(DISTINCT r.[RoleId]) AS [Total]
FROM [identity].[Roles] r
INNER JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
INNER JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
WHERE r.[IsActive] = 1
  AND UPPER(p.[PermissionCode]) = :permissionCode
SQL,
            [':permissionCode' => $permissionCode->toString()],
        );

        return $rows === [] ? 0 : AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
    }

    public function countActiveUsersDependingOn(PermissionCode $permissionCode): int
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT COUNT(DISTINCT u.[UserId]) AS [Total]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[StatusCode] = :activeStatus
INNER JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1 AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
INNER JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
INNER JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId] AND p.[IsActive] = 1
WHERE UPPER(p.[PermissionCode]) = :permissionCode
SQL,
            [':activeStatus' => UserStatus::ACTIVE, ':permissionCode' => $permissionCode->toString()],
        );

        return $rows === [] ? 0 : AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
    }

    public function createPermission(
        PermissionCode $permissionCode,
        string $permissionName,
        ?string $description,
        string $moduleCode,
        bool $isActive,
        UserId $actorUserId,
    ): PermissionSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($permissionCode, $permissionName, $description, $moduleCode, $isActive): PermissionSummary {
                $publicId = $this->uuidGenerator->generate()->toString();

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [identity].[Permissions] (
    [PublicId],
    [PermissionCode],
    [PermissionName],
    [Description],
    [ModuleCode],
    [IsActive]
)
OUTPUT INSERTED.[PermissionId]
VALUES (
    :publicId,
    :permissionCode,
    :permissionName,
    :description,
    :moduleCode,
    :isActive
)
SQL,
                    [
                        ':publicId' => $publicId,
                        ':permissionCode' => $permissionCode->toString(),
                        ':permissionName' => $permissionName,
                        ':description' => $description,
                        ':moduleCode' => $moduleCode,
                        ':isActive' => $isActive ? 1 : 0,
                    ],
                );

                if ($rows === []) {
                    throw IdentityPersistenceException::insertFailed('Permission');
                }

                $details = $this->findByCode($permissionCode);
                if ($details === null) {
                    throw IdentityPersistenceException::insertFailed('Permission');
                }

                return $details;
            },
        );
    }

    public function updatePermission(
        PermissionCode $permissionCode,
        string $permissionName,
        ?string $description,
        ?bool $isActive,
        UserId $actorUserId,
    ): PermissionSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($permissionCode, $permissionName, $description, $isActive): PermissionSummary {
                $params = [
                    ':permissionName' => $permissionName,
                    ':description' => $description,
                    ':permissionCode' => $permissionCode->toString(),
                ];

                $activeAssignment = '';
                if ($isActive !== null) {
                    $activeAssignment = ', [IsActive] = :isActive';
                    $params[':isActive'] = $isActive ? 1 : 0;
                }

                $rows = $this->connection->fetchAll(
                    <<<SQL
UPDATE [identity].[Permissions]
SET
    [PermissionName] = :permissionName,
    [Description] = :description,
    [UpdatedAt] = SYSDATETIMEOFFSET()
    {$activeAssignment}
OUTPUT INSERTED.[PermissionId]
WHERE UPPER([PermissionCode]) = :permissionCode
SQL,
                    $params,
                );

                if ($rows === []) {
                    throw IdentityPersistenceException::permissionNotFound($permissionCode->toString());
                }

                $details = $this->findByCode($permissionCode);
                if ($details === null) {
                    throw IdentityPersistenceException::permissionNotFound($permissionCode->toString());
                }

                return $details;
            },
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePermissionSummary(array $row): PermissionSummary
    {
        $roleCodesRaw = AzureSqlValueMapper::nullableString($row['RoleCodes'] ?? null);
        $roleCodes = $roleCodesRaw === null
            ? []
            : array_values(array_filter(
                array_map('trim', explode(',', $roleCodesRaw)),
                static fn (string $code): bool => $code !== '',
            ));

        return new PermissionSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            permissionCode: AzureSqlValueMapper::stringValue($row['PermissionCode'] ?? null),
            permissionName: AzureSqlValueMapper::stringValue($row['PermissionName'] ?? null),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            moduleCode: AzureSqlValueMapper::stringValue($row['ModuleCode'] ?? null),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            roleCodes: $roleCodes,
            createdAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['CreatedAt'] ?? null, 'CreatedAt'),
            ),
            updatedAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['UpdatedAt'] ?? null, 'UpdatedAt'),
            ),
        );
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
}
