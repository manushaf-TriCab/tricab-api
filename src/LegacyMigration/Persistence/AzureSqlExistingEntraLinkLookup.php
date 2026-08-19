<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\LegacyMigration\Contract\ExistingEntraLinkLookup;

/**
 * SELECT-only. Never inserts or updates ExternalIdentities.
 */
final class AzureSqlExistingEntraLinkLookup implements ExistingEntraLinkLookup
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function linkedNormalizedEmails(array $normalizedEmails): array
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
        foreach (array_chunk(array_values($unique), 80) as $chunk) {
            $params = [];
            $placeholders = [];
            foreach ($chunk as $i => $email) {
                $key = ':e' . $i;
                $placeholders[] = $key;
                $params[$key] = $email;
            }
            $sql = <<<'SQL'
SELECT DISTINCT LOWER(u.[NormalizedEmailAddress]) AS [NormalizedEmailAddress]
FROM [identity].[Users] u
INNER JOIN [identity].[ExternalIdentities] e ON e.[UserId] = u.[UserId]
WHERE (e.[ProviderCode] = :providerA OR e.[ProviderCode] = :providerB)
  AND e.[IsActive] = 1
  AND u.[NormalizedEmailAddress] IN (%s)
SQL;
            $sql = sprintf($sql, implode(',', $placeholders));
            $params[':providerA'] = 'microsoft_entra';
            $params[':providerB'] = 'microsoft-entra';
            foreach ($this->connection->fetchAll($sql, $params) as $row) {
                $value = strtolower(AzureSqlValueMapper::stringValue($row['NormalizedEmailAddress'] ?? ''));
                if ($value !== '') {
                    $found[] = $value;
                }
            }
        }

        return array_values(array_unique($found));
    }
}
