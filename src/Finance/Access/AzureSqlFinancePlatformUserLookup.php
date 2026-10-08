<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Access;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Access\FinancePlatformUserLookup;

/**
 * Platform users by email, from `identity.Users` (the primary address) and
 * `identity.UserEmailAddresses` (every address a person has proven they control).
 * Read only. A disabled user still matches: their historical rows are theirs.
 */
final class AzureSqlFinancePlatformUserLookup implements FinancePlatformUserLookup
{
    private const CHUNK = 500;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function userIdsByEmail(array $emails): array
    {
        $out = [];
        foreach (array_chunk($emails, self::CHUNK) as $chunk) {
            // Named parameters cannot repeat in one statement, so each IN list has its own.
            $params = [];
            $first = [];
            $second = [];
            foreach ($chunk as $position => $email) {
                $first[] = ':a' . $position;
                $second[] = ':b' . $position;
                $params['a' . $position] = $email;
                $params['b' . $position] = $email;
            }
            $inFirst = implode(', ', $first);
            $inSecond = implode(', ', $second);
            foreach ($this->connection->fetchAll(
                "SELECT [NormalizedEmailAddress] AS [email], [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] IN ({$inFirst})
                 UNION
                 SELECT [NormalizedEmailAddress], [UserId] FROM [identity].[UserEmailAddresses]
                  WHERE [IsActive] = 1 AND [NormalizedEmailAddress] IN ({$inSecond})",
                $params,
            ) as $row) {
                $email = strtolower(AzureSqlValueMapper::stringValue($row['email']));
                $id = AzureSqlValueMapper::intValue($row['UserId']);
                if (!in_array($id, $out[$email] ?? [], true)) {
                    $out[$email][] = $id;
                }
            }
        }

        return $out;
    }
}
