<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Core\Identity\Application\DTO\UserManagement\UserSummary;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserTypeClassifier;

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
    ) AS [ProviderCodes],
    cust.[CustomerPublicId],
    cust.[CustomerName],
    cust.[ContactPublicId],
    cust.[ContactDisplayName],
    cust.[PortalAccessStatus]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
OUTER APPLY (
    SELECT TOP (1)
        CONVERT(NVARCHAR(36), c.[PublicId]) AS [CustomerPublicId],
        c.[CustomerName] AS [CustomerName],
        CONVERT(NVARCHAR(36), cc.[PublicId]) AS [ContactPublicId],
        cc.[DisplayName] AS [ContactDisplayName],
        cc.[PortalAccessStatusCode] AS [PortalAccessStatus]
    FROM [customer].[CustomerContactUsers] ccu
    INNER JOIN [customer].[CustomerContacts] cc ON cc.[CustomerContactId] = ccu.[CustomerContactId]
    INNER JOIN [customer].[Customers] c ON c.[CustomerId] = cc.[CustomerId]
    WHERE ccu.[UserId] = u.[UserId]
      AND ccu.[IsActive] = 1
    ORDER BY ccu.[IsPrimary] DESC, ccu.[LinkedAtUtc] DESC
) cust
SQL;

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateUserSummary(array $row): UserSummary
    {
        $created = AzureSqlValueMapper::requireDateTime($row['CreatedAt'] ?? null, 'CreatedAt');
        $updated = AzureSqlValueMapper::requireDateTime($row['UpdatedAt'] ?? null, 'UpdatedAt');
        $lastLogin = AzureSqlValueMapper::nullableDateTime($row['LastSuccessfulLoginAt'] ?? null);

        $roles = $this->splitCsv($row['RoleCodes'] ?? null);
        $providers = $this->splitCsv($row['ProviderCodes'] ?? null);

        return new UserSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            emailAddress: AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? null),
            displayName: $this->resolveDisplayName($row),
            status: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? null),
            roles: $roles,
            providers: $providers,
            createdAtUtc: AzureSqlValueMapper::formatIso8601($created),
            updatedAtUtc: AzureSqlValueMapper::formatIso8601($updated),
            lastLoginAtUtc: $lastLogin !== null ? AzureSqlValueMapper::formatIso8601($lastLogin) : null,
            userType: UserTypeClassifier::fromRoleCodes($roles),
            customerPublicId: AzureSqlValueMapper::nullableString($row['CustomerPublicId'] ?? null),
            customerName: AzureSqlValueMapper::nullableString($row['CustomerName'] ?? null),
            contactPublicId: AzureSqlValueMapper::nullableString($row['ContactPublicId'] ?? null),
            contactDisplayName: AzureSqlValueMapper::nullableString($row['ContactDisplayName'] ?? null),
            portalAccessStatus: AzureSqlValueMapper::nullableString($row['PortalAccessStatus'] ?? null),
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
