<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Core\Identity\Application\DTO\UserManagement\UserSummary;

/**
 * Shared SELECT fragment and row hydration for UserSummary projections,
 * reused by the user and role Azure SQL repositories.
 */
trait AzureSqlUserSummaryHydration
{
    private const USER_SUMMARY_SELECT_SQL = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), u.[PublicId]) AS [PublicId],
    u.[EmailAddress],
    u.[DisplayName],
    s.[StatusCode],
    CONVERT(VARCHAR(33), u.[CreatedAt], 127) AS [CreatedAt],
    CONVERT(VARCHAR(33), u.[UpdatedAt], 127) AS [UpdatedAt],
    CONVERT(VARCHAR(33), u.[LastSuccessfulLoginAt], 127) AS [LastSuccessfulLoginAt],
    (
        SELECT STRING_AGG(rr.[RoleCode], ',')
        FROM [identity].[UserRoles] urr
        INNER JOIN [identity].[Roles] rr ON rr.[RoleId] = urr.[RoleId]
        WHERE urr.[UserId] = u.[UserId]
          AND urr.[IsActive] = 1
          AND (urr.[ExpiresAt] IS NULL OR urr.[ExpiresAt] > SYSDATETIMEOFFSET())
    ) AS [RoleCodes],
    (
        SELECT STRING_AGG(eip.[ProviderCode], ',')
        FROM [identity].[ExternalIdentities] eip
        WHERE eip.[UserId] = u.[UserId]
          AND eip.[IsActive] = 1
    ) AS [ProviderCodes]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
SQL;

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateUserSummary(array $row): UserSummary
    {
        $created = AzureSqlValueMapper::requireDateTime($row['CreatedAt'] ?? null, 'CreatedAt');
        $updated = AzureSqlValueMapper::requireDateTime($row['UpdatedAt'] ?? null, 'UpdatedAt');
        $lastLogin = AzureSqlValueMapper::nullableDateTime($row['LastSuccessfulLoginAt'] ?? null);

        return new UserSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            emailAddress: AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? null),
            displayName: $this->resolveDisplayName($row),
            status: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? null),
            roles: $this->splitCsv($row['RoleCodes'] ?? null),
            providers: $this->splitCsv($row['ProviderCodes'] ?? null),
            createdAtUtc: AzureSqlValueMapper::formatIso8601($created),
            updatedAtUtc: AzureSqlValueMapper::formatIso8601($updated),
            lastLoginAtUtc: $lastLogin !== null ? AzureSqlValueMapper::formatIso8601($lastLogin) : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveDisplayName(array $row): string
    {
        $displayName = AzureSqlValueMapper::nullableString($row['DisplayName'] ?? null);
        if ($displayName !== null) {
            return $displayName;
        }

        return AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function splitCsv(mixed $value): array
    {
        $string = AzureSqlValueMapper::nullableString($value);
        if ($string === null) {
            return [];
        }

        $parts = array_filter(
            array_map('trim', explode(',', $string)),
            static fn (string $part): bool => $part !== '',
        );

        return array_values($parts);
    }
}
