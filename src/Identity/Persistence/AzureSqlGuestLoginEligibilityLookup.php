<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\GuestLoginEligibility;
use TriCab\Core\Identity\Contract\GuestLoginEligibilityLookup;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Single-round-trip Azure SQL eligibility check for guest OTP request.
 *
 * Authentication only: registered active user with ACCOUNT.LOGIN via an active,
 * unexpired role. Does not join customer tables.
 *
 * Business-approved behaviour: ineligible emails are told they are not registered
 * (account enumeration risk accepted). Internal reason codes stay server-side.
 */
final class AzureSqlGuestLoginEligibilityLookup implements GuestLoginEligibilityLookup
{
    private const SQL = <<<'SQL'
SELECT
    CASE WHEN u.[UserId] IS NULL THEN 0 ELSE 1 END AS [UserExists],
    CASE
        WHEN u.[UserId] IS NOT NULL
         AND LOWER(s.[StatusCode]) = N'active'
         AND s.[AllowsLogin] = 1
        THEN 1 ELSE 0
    END AS [ActiveUser],
    CASE
        WHEN EXISTS (
            SELECT 1
            FROM [identity].[UserRoles] ur
            INNER JOIN [identity].[Roles] r
                ON r.[RoleId] = ur.[RoleId]
               AND r.[IsActive] = 1
            INNER JOIN [identity].[RolePermissions] rp
                ON rp.[RoleId] = r.[RoleId]
               AND rp.[IsActive] = 1
            INNER JOIN [identity].[Permissions] p
                ON p.[PermissionId] = rp.[PermissionId]
               AND p.[IsActive] = 1
               AND UPPER(p.[PermissionCode]) = UPPER(:loginPermission)
            WHERE ur.[UserId] = u.[UserId]
              AND ur.[IsActive] = 1
              AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
        ) THEN 1 ELSE 0
    END AS [HasLoginPermission]
FROM (SELECT CAST(1 AS INT) AS [Probe]) probe
LEFT JOIN [identity].[Users] u
    ON u.[NormalizedEmailAddress] = :email
LEFT JOIN [identity].[UserStatuses] s
    ON s.[UserStatusId] = u.[UserStatusId]
   AND s.[IsActive] = 1
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function evaluate(EmailAddress $emailAddress): GuestLoginEligibility
    {
        $rows = $this->connection->fetchAll(self::SQL, [
            ':email' => $emailAddress->toString(),
            ':loginPermission' => ProtectedIdentityCatalog::PERMISSION_ACCOUNT_LOGIN,
        ]);

        if ($rows === []) {
            return GuestLoginEligibility::notFound();
        }

        $row = $rows[0];

        return GuestLoginEligibility::fromFlags(
            userExists: $this->boolFlag($row['UserExists'] ?? 0),
            activeUser: $this->boolFlag($row['ActiveUser'] ?? 0),
            hasLoginPermission: $this->boolFlag($row['HasLoginPermission'] ?? 0),
        );
    }

    private function boolFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            return $value === '1' || strtolower($value) === 'true';
        }

        return false;
    }
}
