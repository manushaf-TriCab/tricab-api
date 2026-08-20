<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\LegacyMigration\Contract\ExistingStaffUserDetailLookup;

/** SELECT-only. Never writes to identity.Users. */
final class AzureSqlExistingStaffUserDetailLookup implements ExistingStaffUserDetailLookup
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function byNormalizedEmails(array $normalizedEmails): array
    {
        $unique = [];
        foreach ($normalizedEmails as $email) {
            $trimmed = strtolower(trim($email));
            if ($trimmed !== '') {
                $unique[$trimmed] = $trimmed;
            }
        }
        if ($unique === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk(array_values($unique), 40) as $chunk) {
            $params = [];
            $placeholders = [];
            foreach ($chunk as $i => $email) {
                $key = ':e' . $i;
                $placeholders[] = $key;
                $params[$key] = $email;
            }
            $sql = <<<'SQL'
SELECT
    u.[UserId],
    CONVERT(NVARCHAR(36), u.[PublicId]) AS [PublicId],
    u.[EmailAddress],
    u.[NormalizedEmailAddress],
    u.[DisplayName],
    s.[StatusCode],
    r.[RoleCode],
    e.[ProviderCode]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
LEFT JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1
LEFT JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
LEFT JOIN [identity].[ExternalIdentities] e ON e.[UserId] = u.[UserId] AND e.[IsActive] = 1
WHERE u.[NormalizedEmailAddress] IN (%s)
SQL;
            $sql = sprintf($sql, implode(',', $placeholders));
            foreach ($this->connection->fetchAll($sql, $params) as $row) {
                $normalized = strtolower(AzureSqlValueMapper::stringValue($row['NormalizedEmailAddress'] ?? ''));
                if ($normalized === '') {
                    continue;
                }
                if (!isset($found[$normalized])) {
                    $found[$normalized] = [
                        'userId' => AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
                        'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                        'emailAddress' => AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? ''),
                        'normalizedEmailAddress' => $normalized,
                        'displayName' => AzureSqlValueMapper::nullableString($row['DisplayName'] ?? null),
                        'statusCode' => AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
                        'roleCodes' => [],
                        'externalProviders' => [],
                    ];
                }
                $roleCode = strtoupper(AzureSqlValueMapper::stringValue($row['RoleCode'] ?? ''));
                if ($roleCode !== '' && !in_array($roleCode, $found[$normalized]['roleCodes'], true)) {
                    $found[$normalized]['roleCodes'][] = $roleCode;
                }
                $provider = AzureSqlValueMapper::stringValue($row['ProviderCode'] ?? '');
                if ($provider !== '' && !in_array($provider, $found[$normalized]['externalProviders'], true)) {
                    $found[$normalized]['externalProviders'][] = $provider;
                }
            }
        }

        return $found;
    }
}
