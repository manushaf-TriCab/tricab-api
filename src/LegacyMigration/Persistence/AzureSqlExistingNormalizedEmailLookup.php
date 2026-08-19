<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\LegacyMigration\Contract\ExistingNormalizedEmailLookup;

/**
 * SELECT-only. Never inserts into identity.Users.
 */
final class AzureSqlExistingNormalizedEmailLookup implements ExistingNormalizedEmailLookup
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function existingNormalizedEmails(array $normalizedEmails): array
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
        $chunks = array_chunk(array_values($unique), 80);
        foreach ($chunks as $chunk) {
            $params = [];
            $placeholders = [];
            foreach ($chunk as $i => $email) {
                $key = ':e' . $i;
                $placeholders[] = $key;
                $params[$key] = $email;
            }
            $sql = 'SELECT [NormalizedEmailAddress] FROM [identity].[Users] WHERE [NormalizedEmailAddress] IN (' . implode(',', $placeholders) . ')';
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
