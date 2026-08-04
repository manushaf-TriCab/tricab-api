<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\EffectivePermissionGrant;
use TriCab\Core\Identity\Application\DTO\UserManagement\IdentityProviderSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\Pagination;
use TriCab\Core\Identity\Application\DTO\UserManagement\RolePermissionSource;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListResult;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementSummaryCounts;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserRoleAssignment;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserSummary;
use TriCab\Core\Identity\Application\Service\UserManagement\EffectivePermissionCalculator;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Azure SQL implementation of the Core UserManagementRepository contract.
 * Reads and writes prepared statements only; identifiers such as sort column
 * and search text never reach the SQL text — only whitelisted fragments and
 * bound parameters do.
 */
final class AzureSqlUserManagementRepository implements UserManagementRepository
{
    use AzureSqlTransaction;
    use AzureSqlUserSummaryHydration;

    private readonly EffectivePermissionCalculator $permissionCalculator;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly UuidV4Generator $uuidGenerator = new UuidV4Generator(),
    ) {
        $this->permissionCalculator = new EffectivePermissionCalculator();
    }

    public function listUsers(UserListCriteria $criteria): UserListResult
    {
        [$where, $filterParams] = $this->buildUserFilters($criteria);

        $countRows = $this->connection->fetchAll(
            <<<SQL
SELECT COUNT(*) AS [Total]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
WHERE 1 = 1{$where}
SQL,
            $filterParams,
        );
        $total = $countRows === [] ? 0 : AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);

        $sortColumn = $this->sortColumn($criteria->sortBy);
        $sortDirection = $criteria->normalizedSortDirection() === UserListCriteria::SORT_ASC ? 'ASC' : 'DESC';
        $offset = ($criteria->page - 1) * $criteria->pageSize;

        $rows = $this->connection->fetchAll(
            self::USER_SUMMARY_SELECT_SQL . <<<SQL

WHERE 1 = 1{$where}
ORDER BY {$sortColumn} {$sortDirection}
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
SQL,
            [...$filterParams, ':offset' => $offset, ':pageSize' => $criteria->pageSize],
        );

        $items = array_map(fn (array $row): UserSummary => $this->hydrateUserSummary($row), $rows);

        return new UserListResult(
            items: $items,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
            counts: $this->summarizeCounts(),
        );
    }

    public function findDetailsByPublicId(UserPublicId $publicId): ?UserDetails
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    u.[UserId],
    CONVERT(NVARCHAR(36), u.[PublicId]) AS [PublicId],
    u.[EmailAddress],
    u.[DisplayName],
    s.[StatusCode],
    CONVERT(VARCHAR(33), u.[CreatedAt], 127) AS [CreatedAt],
    CONVERT(VARCHAR(33), u.[UpdatedAt], 127) AS [UpdatedAt],
    CONVERT(VARCHAR(33), u.[LastSuccessfulLoginAt], 127) AS [LastSuccessfulLoginAt]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
WHERE u.[PublicId] = :publicId
SQL,
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $userId = AzureSqlValueMapper::intValue($row['UserId'] ?? null);

        $created = AzureSqlValueMapper::requireDateTime($row['CreatedAt'] ?? null, 'CreatedAt');
        $updated = AzureSqlValueMapper::requireDateTime($row['UpdatedAt'] ?? null, 'UpdatedAt');
        $lastLogin = AzureSqlValueMapper::nullableDateTime($row['LastSuccessfulLoginAt'] ?? null);

        return new UserDetails(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            emailAddress: AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? null),
            displayName: $this->resolveDisplayName($row),
            status: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? null),
            roles: $this->fetchUserRoleAssignments($userId),
            effectivePermissions: $this->fetchEffectivePermissions($userId),
            identityProviders: $this->fetchIdentityProviderSummaries($userId),
            createdAtUtc: AzureSqlValueMapper::formatIso8601($created),
            updatedAtUtc: AzureSqlValueMapper::formatIso8601($updated),
            lastLoginAtUtc: $lastLogin !== null ? AzureSqlValueMapper::formatIso8601($lastLogin) : null,
            recentAuditEvents: [],
        );
    }

    public function findUserIdByPublicId(UserPublicId $publicId): ?UserId
    {
        $userId = $this->userIdByPublicId($publicId);

        return $userId !== null ? UserId::fromInt($userId) : null;
    }

    public function emailExists(EmailAddress $emailAddress, ?UserPublicId $exceptPublicId = null): bool
    {
        $sql = 'SELECT TOP 1 1 AS [Found] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :email';
        $params = [':email' => $emailAddress->toString()];

        if ($exceptPublicId !== null) {
            $sql .= ' AND [PublicId] <> :exceptPublicId';
            $params[':exceptPublicId'] = $exceptPublicId->toString();
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    /**
     * @param list<RoleCode> $roleCodes
     */
    public function createUser(
        EmailAddress $emailAddress,
        string $displayName,
        UserStatus $status,
        array $roleCodes,
        UserId $actorUserId,
    ): UserDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($emailAddress, $displayName, $status, $roleCodes, $actorUserId): UserDetails {
                $statusId = $this->resolveStatusId($status);
                $publicId = $this->uuidGenerator->generate()->toString();

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [identity].[Users] (
    [PublicId],
    [EmailAddress],
    [NormalizedEmailAddress],
    [DisplayName],
    [UserStatusId]
)
OUTPUT INSERTED.[UserId]
VALUES (
    :publicId,
    :email,
    :normalizedEmail,
    :displayName,
    :statusId
)
SQL,
                    [
                        ':publicId' => $publicId,
                        ':email' => $emailAddress->toString(),
                        ':normalizedEmail' => $emailAddress->toString(),
                        ':displayName' => $displayName,
                        ':statusId' => $statusId,
                    ],
                );

                if ($rows === []) {
                    throw IdentityPersistenceException::insertFailed('User');
                }

                $userId = AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? null);

                foreach ($roleCodes as $roleCode) {
                    $this->insertUserRole($userId, $roleCode, null, $actorUserId);
                }

                $details = $this->findDetailsByPublicId(UserPublicId::fromString($publicId));
                if ($details === null) {
                    throw IdentityPersistenceException::insertFailed('User');
                }

                return $details;
            },
        );
    }

    public function updateUser(
        UserPublicId $publicId,
        string $displayName,
        ?EmailAddress $emailAddress,
        UserId $actorUserId,
    ): UserDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($publicId, $displayName, $emailAddress): UserDetails {
                $params = [
                    ':displayName' => $displayName,
                    ':publicId' => $publicId->toString(),
                ];

                $emailAssignment = '';
                if ($emailAddress !== null) {
                    $emailAssignment = ', [EmailAddress] = :email, [NormalizedEmailAddress] = :normalizedEmail';
                    $params[':email'] = $emailAddress->toString();
                    $params[':normalizedEmail'] = $emailAddress->toString();
                }

                $rows = $this->connection->fetchAll(
                    <<<SQL
UPDATE [identity].[Users]
SET
    [DisplayName] = :displayName,
    [UpdatedAt] = SYSDATETIMEOFFSET()
    {$emailAssignment}
OUTPUT INSERTED.[UserId]
WHERE [PublicId] = :publicId
SQL,
                    $params,
                );

                if ($rows === []) {
                    throw IdentityPersistenceException::userNotFound($publicId->toString());
                }

                $details = $this->findDetailsByPublicId($publicId);
                if ($details === null) {
                    throw IdentityPersistenceException::userNotFound($publicId->toString());
                }

                return $details;
            },
        );
    }

    public function changeStatus(
        UserPublicId $publicId,
        UserStatus $status,
        UserId $actorUserId,
    ): UserDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($publicId, $status): UserDetails {
                $statusId = $this->resolveStatusId($status);

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [identity].[Users]
SET
    [UserStatusId] = :statusId,
    [UpdatedAt] = SYSDATETIMEOFFSET()
OUTPUT INSERTED.[UserId]
WHERE [PublicId] = :publicId
SQL,
                    [':statusId' => $statusId, ':publicId' => $publicId->toString()],
                );

                if ($rows === []) {
                    throw IdentityPersistenceException::userNotFound($publicId->toString());
                }

                $details = $this->findDetailsByPublicId($publicId);
                if ($details === null) {
                    throw IdentityPersistenceException::userNotFound($publicId->toString());
                }

                return $details;
            },
        );
    }

    public function assignRole(
        UserPublicId $publicId,
        RoleCode $roleCode,
        ?DateTimeImmutable $expiresAtUtc,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($publicId, $roleCode, $expiresAtUtc, $actorUserId): void {
                $userId = $this->requireUserIdByPublicId($publicId);
                $roleId = $this->requireActiveRoleId($roleCode);
                $expiresParam = $expiresAtUtc !== null
                    ? AzureSqlValueMapper::formatDateTimeOffset($expiresAtUtc)
                    : null;

                $existingRows = $this->connection->fetchAll(
                    <<<'SQL'
SELECT [UserRoleId], [IsActive], CONVERT(VARCHAR(33), [ExpiresAt], 127) AS [ExpiresAt]
FROM [identity].[UserRoles]
WHERE [UserId] = :userId AND [RoleId] = :roleId
SQL,
                    [':userId' => $userId, ':roleId' => $roleId],
                );

                if ($existingRows === []) {
                    $this->connection->execute(
                        <<<'SQL'
INSERT INTO [identity].[UserRoles] (
    [UserId],
    [RoleId],
    [AssignedByUserId],
    [ExpiresAt],
    [IsActive]
)
VALUES (
    :userId,
    :roleId,
    :assignedByUserId,
    :expiresAt,
    1
)
SQL,
                        [
                            ':userId' => $userId,
                            ':roleId' => $roleId,
                            ':assignedByUserId' => $actorUserId->toInt(),
                            ':expiresAt' => $expiresParam,
                        ],
                    );
                } else {
                    $existing = $existingRows[0];
                    $isActive = AzureSqlValueMapper::boolValue($existing['IsActive'] ?? false);
                    $existingExpires = AzureSqlValueMapper::nullableDateTime($existing['ExpiresAt'] ?? null);
                    $isExpired = $existingExpires !== null
                        && $existingExpires <= new DateTimeImmutable('now', new DateTimeZone('UTC'));

                    if ($isActive && !$isExpired) {
                        return;
                    }

                    $this->connection->execute(
                        <<<'SQL'
UPDATE [identity].[UserRoles]
SET
    [IsActive] = 1,
    [AssignedAt] = SYSDATETIMEOFFSET(),
    [AssignedByUserId] = :assignedByUserId,
    [ExpiresAt] = :expiresAt
WHERE [UserId] = :userId AND [RoleId] = :roleId
SQL,
                        [
                            ':assignedByUserId' => $actorUserId->toInt(),
                            ':expiresAt' => $expiresParam,
                            ':userId' => $userId,
                            ':roleId' => $roleId,
                        ],
                    );
                }

                $this->touchUser($userId);
            },
        );
    }

    public function removeRole(UserPublicId $publicId, RoleCode $roleCode): bool
    {
        return $this->runInTransaction(
            $this->connection,
            function () use ($publicId, $roleCode): bool {
                $userId = $this->userIdByPublicId($publicId);
                if ($userId === null) {
                    return false;
                }

                $roleId = $this->roleIdByCode($roleCode);
                if ($roleId === null) {
                    return false;
                }

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [identity].[UserRoles]
SET [IsActive] = 0
OUTPUT INSERTED.[UserRoleId]
WHERE [UserId] = :userId AND [RoleId] = :roleId AND [IsActive] = 1
SQL,
                    [':userId' => $userId, ':roleId' => $roleId],
                );

                if ($rows === []) {
                    return false;
                }

                $this->touchUser($userId);

                return true;
            },
        );
    }

    public function userHasActiveRole(UserPublicId $publicId, RoleCode $roleCode): bool
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1 1 AS [Found]
FROM [identity].[UserRoles] ur
INNER JOIN [identity].[Users] u ON u.[UserId] = ur.[UserId]
INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
WHERE u.[PublicId] = :publicId
  AND UPPER(r.[RoleCode]) = :roleCode
  AND ur.[IsActive] = 1
  AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
SQL,
            [':publicId' => $publicId->toString(), ':roleCode' => $roleCode->toString()],
        );

        return $rows !== [];
    }

    public function countActiveUsersWithRole(RoleCode $roleCode): int
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT COUNT(DISTINCT u.[UserId]) AS [Total]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[StatusCode] = :activeStatus
INNER JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId]
    AND ur.[IsActive] = 1
    AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
WHERE UPPER(r.[RoleCode]) = :roleCode
SQL,
            [':activeStatus' => UserStatus::ACTIVE, ':roleCode' => $roleCode->toString()],
        );

        return $rows === [] ? 0 : AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
    }

    public function summarizeCounts(): UserManagementSummaryCounts
    {
        // sqlsrv PDO requires unique named parameters — never reuse :name in one statement.
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    (SELECT COUNT(*) FROM [identity].[Users]) AS [TotalUsers],
    (
        SELECT COUNT(*)
        FROM [identity].[Users] u
        INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
        WHERE s.[StatusCode] = :activeStatusTotal
    ) AS [ActiveUsers],
    (
        SELECT COUNT(*)
        FROM [identity].[Users] u
        INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
        WHERE s.[StatusCode] = :disabledStatus
    ) AS [SuspendedUsers],
    (
        SELECT COUNT(DISTINCT u.[UserId])
        FROM [identity].[Users] u
        INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[StatusCode] = :activeStatusGuest
        INNER JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1 AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
        INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
        WHERE UPPER(r.[RoleCode]) = :guestRole
    ) AS [GuestUsers],
    (
        SELECT COUNT(DISTINCT e.[UserId])
        FROM [identity].[ExternalIdentities] e
        WHERE e.[IsActive] = 1
          AND (e.[ProviderCode] = :providerA OR e.[ProviderCode] = :providerB)
    ) AS [MicrosoftUsers],
    (
        SELECT COUNT(DISTINCT u.[UserId])
        FROM [identity].[Users] u
        INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] AND s.[StatusCode] = :activeStatusAdmin
        INNER JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1 AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
        INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
        WHERE UPPER(r.[RoleCode]) = :adminRole
    ) AS [Administrators]
SQL,
            [
                ':activeStatusTotal' => UserStatus::ACTIVE,
                ':disabledStatus' => UserStatus::DISABLED,
                ':activeStatusGuest' => UserStatus::ACTIVE,
                ':guestRole' => ProtectedIdentityCatalog::ROLE_GUEST,
                ':activeStatusAdmin' => UserStatus::ACTIVE,
                ':adminRole' => ProtectedIdentityCatalog::ROLE_ADMINISTRATOR,
                ':providerA' => ExternalProvider::MICROSOFT_ENTRA,
                ':providerB' => str_replace('_', '-', ExternalProvider::MICROSOFT_ENTRA),
            ],
        );

        /** @var array<string, mixed> $row */
        $row = $rows[0] ?? [];

        return new UserManagementSummaryCounts(
            totalUsers: AzureSqlValueMapper::intValue($row['TotalUsers'] ?? 0),
            activeUsers: AzureSqlValueMapper::intValue($row['ActiveUsers'] ?? 0),
            suspendedUsers: AzureSqlValueMapper::intValue($row['SuspendedUsers'] ?? 0),
            guestUsers: AzureSqlValueMapper::intValue($row['GuestUsers'] ?? 0),
            microsoftUsers: AzureSqlValueMapper::intValue($row['MicrosoftUsers'] ?? 0),
            administrators: AzureSqlValueMapper::intValue($row['Administrators'] ?? 0),
        );
    }

    /**
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function buildUserFilters(UserListCriteria $criteria): array
    {
        $conditions = [];
        $params = [];

        $search = $criteria->normalizedSearch();
        if ($search !== null) {
            // Unique :search* names — sqlsrv PDO rejects repeated named parameters.
            $conditions[] = '(u.[NormalizedEmailAddress] LIKE :searchEmail OR u.[DisplayName] LIKE :searchName OR CONVERT(NVARCHAR(36), u.[PublicId]) LIKE :searchPublicId)';
            $needle = '%' . $search . '%';
            $params[':searchEmail'] = $needle;
            $params[':searchName'] = $needle;
            $params[':searchPublicId'] = $needle;
        }

        if ($criteria->status !== null) {
            $conditions[] = 's.[StatusCode] = :status';
            $params[':status'] = strtolower($criteria->status);
        }

        if ($criteria->roleCode !== null) {
            $conditions[] = <<<'SQL'
EXISTS (
    SELECT 1
    FROM [identity].[UserRoles] urf
    INNER JOIN [identity].[Roles] rf ON rf.[RoleId] = urf.[RoleId]
    WHERE urf.[UserId] = u.[UserId]
      AND urf.[IsActive] = 1
      AND (urf.[ExpiresAt] IS NULL OR urf.[ExpiresAt] > SYSDATETIMEOFFSET())
      AND UPPER(rf.[RoleCode]) = :roleCode
)
SQL;
            $params[':roleCode'] = strtoupper($criteria->roleCode);
        }

        if ($criteria->providerCode !== null) {
            $normalized = str_replace('-', '_', strtolower($criteria->providerCode));
            $alt = str_replace('_', '-', $normalized);
            $conditions[] = <<<'SQL'
EXISTS (
    SELECT 1
    FROM [identity].[ExternalIdentities] eif
    WHERE eif.[UserId] = u.[UserId]
      AND eif.[IsActive] = 1
      AND (eif.[ProviderCode] = :provider OR eif.[ProviderCode] = :providerAlt)
)
SQL;
            $params[':provider'] = $normalized;
            $params[':providerAlt'] = $alt;
        }

        $where = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

        return [$where, $params];
    }

    private function sortColumn(string $sortBy): string
    {
        return match ($sortBy) {
            UserListCriteria::SORT_EMAIL => 'u.[NormalizedEmailAddress]',
            UserListCriteria::SORT_DISPLAY_NAME => 'u.[DisplayName]',
            UserListCriteria::SORT_STATUS => 's.[StatusCode]',
            UserListCriteria::SORT_UPDATED_AT => 'u.[UpdatedAt]',
            UserListCriteria::SORT_LAST_LOGIN_AT => 'u.[LastSuccessfulLoginAt]',
            default => 'u.[CreatedAt]',
        };
    }

    /**
     * @return list<UserRoleAssignment>
     */
    private function fetchUserRoleAssignments(int $userId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    r.[RoleCode],
    r.[RoleName],
    CONVERT(VARCHAR(33), ur.[AssignedAt], 127) AS [AssignedAt],
    CONVERT(VARCHAR(33), ur.[ExpiresAt], 127) AS [ExpiresAt],
    CASE WHEN ur.[ExpiresAt] IS NOT NULL AND ur.[ExpiresAt] <= SYSDATETIMEOFFSET() THEN 1 ELSE 0 END AS [IsExpired]
FROM [identity].[UserRoles] ur
INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
WHERE ur.[UserId] = :userId
  AND ur.[IsActive] = 1
ORDER BY r.[RoleCode]
SQL,
            [':userId' => $userId],
        );

        return array_map(function (array $row): UserRoleAssignment {
            $assignedAt = AzureSqlValueMapper::requireDateTime($row['AssignedAt'] ?? null, 'AssignedAt');
            $expiresAt = AzureSqlValueMapper::nullableDateTime($row['ExpiresAt'] ?? null);

            return new UserRoleAssignment(
                roleCode: AzureSqlValueMapper::stringValue($row['RoleCode'] ?? null),
                roleName: AzureSqlValueMapper::stringValue($row['RoleName'] ?? null),
                isActive: true,
                assignedAtUtc: AzureSqlValueMapper::formatIso8601($assignedAt),
                expiresAtUtc: $expiresAt !== null ? AzureSqlValueMapper::formatIso8601($expiresAt) : null,
                isExpired: AzureSqlValueMapper::boolValue($row['IsExpired'] ?? false),
            );
        }, $rows);
    }

    /**
     * @return list<EffectivePermissionGrant>
     */
    private function fetchEffectivePermissions(int $userId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    r.[RoleCode],
    p.[PermissionCode],
    p.[PermissionName],
    r.[IsActive] AS [RoleIsActive],
    p.[IsActive] AS [PermissionIsActive],
    CASE WHEN ur.[ExpiresAt] IS NOT NULL AND ur.[ExpiresAt] <= SYSDATETIMEOFFSET() THEN 1 ELSE 0 END AS [IsExpired]
FROM [identity].[UserRoles] ur
INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
INNER JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
INNER JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
WHERE ur.[UserId] = :userId
  AND ur.[IsActive] = 1
SQL,
            [':userId' => $userId],
        );

        $sources = array_map(
            static fn (array $row): RolePermissionSource => new RolePermissionSource(
                roleCode: AzureSqlValueMapper::stringValue($row['RoleCode'] ?? null),
                permissionCode: AzureSqlValueMapper::stringValue($row['PermissionCode'] ?? null),
                permissionName: AzureSqlValueMapper::stringValue($row['PermissionName'] ?? null),
                roleIsActive: AzureSqlValueMapper::boolValue($row['RoleIsActive'] ?? false),
                permissionIsActive: AzureSqlValueMapper::boolValue($row['PermissionIsActive'] ?? false),
                assignmentIsActive: true,
                isExpired: AzureSqlValueMapper::boolValue($row['IsExpired'] ?? false),
            ),
            $rows,
        );

        return $this->permissionCalculator->calculate($sources);
    }

    /**
     * @return list<IdentityProviderSummary>
     */
    private function fetchIdentityProviderSummaries(int $userId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    e.[ProviderCode],
    e.[IsActive],
    e.[SubjectIdentifier],
    CONVERT(VARCHAR(33), e.[LastAuthenticatedAt], 127) AS [LastAuthenticatedAt]
FROM [identity].[ExternalIdentities] e
WHERE e.[UserId] = :userId
ORDER BY e.[ProviderCode]
SQL,
            [':userId' => $userId],
        );

        return array_map(function (array $row): IdentityProviderSummary {
            $lastAuth = AzureSqlValueMapper::nullableDateTime($row['LastAuthenticatedAt'] ?? null);

            return new IdentityProviderSummary(
                providerCode: AzureSqlValueMapper::stringValue($row['ProviderCode'] ?? null),
                isLinked: true,
                isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
                lastAuthenticatedAtUtc: $lastAuth !== null ? AzureSqlValueMapper::formatIso8601($lastAuth) : null,
                maskedSubject: $this->maskSubject(AzureSqlValueMapper::stringValue($row['SubjectIdentifier'] ?? null)),
            );
        }, $rows);
    }

    private function maskSubject(string $subject): string
    {
        $trimmed = trim($subject);
        if ($trimmed === '') {
            return '***';
        }

        if (strlen($trimmed) <= 4) {
            return str_repeat('*', strlen($trimmed));
        }

        return substr($trimmed, 0, 4) . '...';
    }

    private function insertUserRole(
        int $userId,
        RoleCode $roleCode,
        ?DateTimeImmutable $expiresAtUtc,
        UserId $actorUserId,
    ): void {
        $roleId = $this->requireActiveRoleId($roleCode);

        $this->connection->execute(
            <<<'SQL'
INSERT INTO [identity].[UserRoles] (
    [UserId],
    [RoleId],
    [AssignedByUserId],
    [ExpiresAt],
    [IsActive]
)
VALUES (
    :userId,
    :roleId,
    :assignedByUserId,
    :expiresAt,
    1
)
SQL,
            [
                ':userId' => $userId,
                ':roleId' => $roleId,
                ':assignedByUserId' => $actorUserId->toInt(),
                ':expiresAt' => $expiresAtUtc !== null ? AzureSqlValueMapper::formatDateTimeOffset($expiresAtUtc) : null,
            ],
        );
    }

    private function resolveStatusId(UserStatus $status): int
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [UserStatusId]
FROM [identity].[UserStatuses]
WHERE [StatusCode] = :statusCode
  AND [IsActive] = 1
SQL,
            [':statusCode' => $status->toString()],
        );

        if ($rows === []) {
            throw IdentityPersistenceException::statusNotFound($status->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['UserStatusId'] ?? null);
    }

    private function requireActiveRoleId(RoleCode $roleCode): int
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [RoleId]
FROM [identity].[Roles]
WHERE UPPER([RoleCode]) = :roleCode
  AND [IsActive] = 1
SQL,
            [':roleCode' => $roleCode->toString()],
        );

        if ($rows === []) {
            throw IdentityPersistenceException::roleNotFound($roleCode->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['RoleId'] ?? null);
    }

    private function userIdByPublicId(UserPublicId $publicId): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [UserId] FROM [identity].[Users] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? null);
    }

    private function requireUserIdByPublicId(UserPublicId $publicId): int
    {
        $userId = $this->userIdByPublicId($publicId);
        if ($userId === null) {
            throw IdentityPersistenceException::userNotFound($publicId->toString());
        }

        return $userId;
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

    private function touchUser(int $userId): void
    {
        $this->connection->execute(
            'UPDATE [identity].[Users] SET [UpdatedAt] = SYSDATETIMEOFFSET() WHERE [UserId] = :userId',
            [':userId' => $userId],
        );
    }
}
