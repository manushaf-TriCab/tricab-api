<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\LegacyMigration\Contract\StaffEntraIdentityRepository;

/** SELECT-only repository for Entra linking reconciliation. */
final class AzureSqlStaffEntraIdentityRepository implements StaffEntraIdentityRepository
{
    private const MAPPING_TABLE = '[migration].[LegacyStaffUserMappings]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function listProviderCodes(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT DISTINCT e.[ProviderCode]
FROM [identity].[ExternalIdentities] e
WHERE e.[IsActive] = 1
ORDER BY e.[ProviderCode]
SQL,
        );

        return array_values(array_filter(array_map(
            static fn (array $row): string => AzureSqlValueMapper::stringValue($row['ProviderCode'] ?? ''),
            $rows,
        )));
    }

    public function listAllStaffMappings(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . self::MAPPING_TABLE . ' ORDER BY [LegacyWordPressUserId] ASC',
        );

        return array_map(fn (array $row): array => $this->hydrateMapping($row), $rows);
    }

    public function loadTriCabUsersByIds(array $userIds): array
    {
        $unique = array_values(array_filter(array_unique(array_map('intval', $userIds)), static fn (int $id): bool => $id > 0));
        if ($unique === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($unique, 40) as $chunk) {
            $params = [];
            $placeholders = [];
            foreach ($chunk as $i => $userId) {
                $key = ':u' . $i;
                $placeholders[] = $key;
                $params[$key] = $userId;
            }
            $sql = <<<'SQL'
SELECT
    u.[UserId],
    u.[EmailAddress],
    u.[NormalizedEmailAddress],
    u.[DisplayName],
    u.[CreatedAt],
    s.[StatusCode],
    r.[RoleCode],
    e.[ProviderCode],
    e.[SubjectIdentifier],
    e.[TenantIdentifier],
    CASE WHEN m.[UserId] IS NOT NULL THEN 1 ELSE 0 END AS [IsBatch1Mapped]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
LEFT JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1
LEFT JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
LEFT JOIN [identity].[ExternalIdentities] e ON e.[UserId] = u.[UserId] AND e.[IsActive] = 1
LEFT JOIN [migration].[LegacyStaffUserMappings] m ON m.[UserId] = u.[UserId]
WHERE u.[UserId] IN (%s)
SQL;
            $sql = sprintf($sql, implode(',', $placeholders));
            foreach ($this->connection->fetchAll($sql, $params) as $row) {
                $userId = AzureSqlValueMapper::intValue($row['UserId'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }
                if (!isset($found[$userId])) {
                    $found[$userId] = [
                        'userId' => $userId,
                        'emailAddress' => AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? ''),
                        'normalizedEmailAddress' => strtolower(AzureSqlValueMapper::stringValue($row['NormalizedEmailAddress'] ?? '')),
                        'displayName' => AzureSqlValueMapper::nullableString($row['DisplayName'] ?? null),
                        'createdAt' => AzureSqlValueMapper::nullableString($row['CreatedAt'] ?? null),
                        'statusCode' => AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
                        'roleCodes' => [],
                        'externalProviders' => [],
                        'externalIdentities' => [],
                        'hasEntraLink' => false,
                        'isBatch1Mapped' => AzureSqlValueMapper::intValue($row['IsBatch1Mapped'] ?? 0) === 1,
                    ];
                }
                $roleCode = strtoupper(AzureSqlValueMapper::stringValue($row['RoleCode'] ?? ''));
                if ($roleCode !== '' && !in_array($roleCode, $found[$userId]['roleCodes'], true)) {
                    $found[$userId]['roleCodes'][] = $roleCode;
                }
                $provider = AzureSqlValueMapper::stringValue($row['ProviderCode'] ?? '');
                if ($provider !== '' && !in_array($provider, $found[$userId]['externalProviders'], true)) {
                    $found[$userId]['externalProviders'][] = $provider;
                }
                $subject = AzureSqlValueMapper::stringValue($row['SubjectIdentifier'] ?? '');
                if ($subject !== '') {
                    $found[$userId]['externalIdentities'][] = [
                        'providerCode' => $provider,
                        'subjectIdentifier' => $subject,
                        'tenantIdentifier' => AzureSqlValueMapper::stringValue($row['TenantIdentifier'] ?? ''),
                    ];
                    if ($this->isEntraProvider($provider)) {
                        $found[$userId]['hasEntraLink'] = true;
                    }
                }
            }
        }

        return $found;
    }

    public function loadTriCabUsersByEmails(array $normalizedEmails): array
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
SELECT u.[UserId]
FROM [identity].[Users] u
WHERE u.[NormalizedEmailAddress] IN (%s)
SQL;
            $sql = sprintf($sql, implode(',', $placeholders));
            $userIds = [];
            foreach ($this->connection->fetchAll($sql, $params) as $row) {
                $userIds[] = AzureSqlValueMapper::intValue($row['UserId'] ?? 0);
            }
            foreach ($this->loadTriCabUsersByIds($userIds) as $userId => $user) {
                $email = (string) ($user['normalizedEmailAddress'] ?? '');
                if ($email === '') {
                    continue;
                }
                if (!isset($found[$email])) {
                    $found[$email] = [];
                }
                $found[$email][] = $user;
            }
        }

        return $found;
    }

    public function loadEntraIdentitiesBySubject(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    e.[UserId],
    e.[ProviderCode],
    e.[TenantIdentifier],
    e.[SubjectIdentifier],
    e.[EmailClaim],
    e.[NormalizedEmailClaim]
FROM [identity].[ExternalIdentities] e
WHERE e.[IsActive] = 1
  AND (e.[ProviderCode] = :providerA OR e.[ProviderCode] = :providerB)
SQL,
            [
                ':providerA' => ExternalProvider::MICROSOFT_ENTRA,
                ':providerB' => str_replace('_', '-', ExternalProvider::MICROSOFT_ENTRA),
            ],
        );

        $found = [];
        foreach ($rows as $row) {
            $subject = AzureSqlValueMapper::stringValue($row['SubjectIdentifier'] ?? '');
            if ($subject === '') {
                continue;
            }
            $found[$subject] = [
                'userId' => AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
                'providerCode' => AzureSqlValueMapper::stringValue($row['ProviderCode'] ?? ''),
                'tenantIdentifier' => AzureSqlValueMapper::stringValue($row['TenantIdentifier'] ?? ''),
                'subjectIdentifier' => $subject,
                'emailClaim' => AzureSqlValueMapper::stringValue($row['EmailClaim'] ?? ''),
                'normalizedEmailClaim' => AzureSqlValueMapper::stringValue($row['NormalizedEmailClaim'] ?? ''),
            ];
        }

        return $found;
    }

    public function loadEntraIdentitiesByUserId(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    e.[UserId],
    e.[ProviderCode],
    e.[TenantIdentifier],
    e.[SubjectIdentifier],
    e.[EmailClaim],
    e.[NormalizedEmailClaim]
FROM [identity].[ExternalIdentities] e
WHERE e.[IsActive] = 1
  AND (e.[ProviderCode] = :providerA OR e.[ProviderCode] = :providerB)
SQL,
            [
                ':providerA' => ExternalProvider::MICROSOFT_ENTRA,
                ':providerB' => str_replace('_', '-', ExternalProvider::MICROSOFT_ENTRA),
            ],
        );

        $found = [];
        foreach ($rows as $row) {
            $userId = AzureSqlValueMapper::intValue($row['UserId'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            if (!isset($found[$userId])) {
                $found[$userId] = [];
            }
            $found[$userId][] = [
                'userId' => $userId,
                'providerCode' => AzureSqlValueMapper::stringValue($row['ProviderCode'] ?? ''),
                'tenantIdentifier' => AzureSqlValueMapper::stringValue($row['TenantIdentifier'] ?? ''),
                'subjectIdentifier' => AzureSqlValueMapper::stringValue($row['SubjectIdentifier'] ?? ''),
                'emailClaim' => AzureSqlValueMapper::stringValue($row['EmailClaim'] ?? ''),
                'normalizedEmailClaim' => AzureSqlValueMapper::stringValue($row['NormalizedEmailClaim'] ?? ''),
            ];
        }

        return $found;
    }

    public function loadCorporateStaffUsers(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT u.[UserId]
FROM [identity].[Users] u
WHERE u.[NormalizedEmailAddress] LIKE :domainPattern
SQL,
            [':domainPattern' => '%@tricab.com'],
        );
        $userIds = array_map(
            static fn (array $row): int => AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            $rows,
        );

        return $this->loadTriCabUsersByIds($userIds);
    }

    /** @param array<string, mixed> $row */
    private function hydrateMapping(array $row): array
    {
        return [
            'legacyStaffUserMappingId' => AzureSqlValueMapper::intValue($row['LegacyStaffUserMappingId'] ?? 0),
            'sourceSystem' => AzureSqlValueMapper::stringValue($row['SourceSystem'] ?? ''),
            'legacyWordPressUserId' => AzureSqlValueMapper::intValue($row['LegacyWordPressUserId'] ?? 0),
            'legacyLogin' => AzureSqlValueMapper::stringValue($row['LegacyUserLogin'] ?? ''),
            'legacyEmailAddress' => AzureSqlValueMapper::stringValue($row['LegacyEmailAddress'] ?? ''),
            'normalizedEmailAddress' => AzureSqlValueMapper::stringValue($row['NormalizedEmailAddress'] ?? ''),
            'userId' => AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            'batchPublicId' => AzureSqlValueMapper::stringValue($row['BatchPublicId'] ?? ''),
            'sourceFingerprint' => AzureSqlValueMapper::stringValue($row['SourceFingerprint'] ?? ''),
            'migrationStatusCode' => AzureSqlValueMapper::stringValue($row['MigrationStatusCode'] ?? ''),
        ];
    }

    private function isEntraProvider(string $provider): bool
    {
        $normalized = str_replace('-', '_', strtolower(trim($provider)));

        return $normalized === ExternalProvider::MICROSOFT_ENTRA;
    }
}
