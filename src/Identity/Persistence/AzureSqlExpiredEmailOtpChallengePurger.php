<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use DateTimeImmutable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\ExpiredEmailOtpChallengePurger;

/**
 * `[identity].[EmailOtpChallenges]` has no trigger and nothing references it,
 * so a row count comes straight from `OUTPUT`.
 */
final class AzureSqlExpiredEmailOtpChallengePurger implements ExpiredEmailOtpChallengePurger
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function countPurgeable(DateTimeImmutable $createdBefore, DateTimeImmutable $nowUtc): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT COUNT_BIG(*) AS [Eligible] FROM [identity].[EmailOtpChallenges]
             WHERE [CreatedAtUtc] < :createdBefore AND [ExpiresAtUtc] < :now',
            [':createdBefore' => AzureSqlValueMapper::formatDateTime($createdBefore), ':now' => AzureSqlValueMapper::formatDateTime($nowUtc)],
        );

        return AzureSqlValueMapper::intValue($rows[0]['Eligible'] ?? 0);
    }

    public function purge(DateTimeImmutable $createdBefore, DateTimeImmutable $nowUtc, int $limit): int
    {
        $rows = $this->connection->fetchAll(
            sprintf(
                'DELETE TOP (%d) FROM [identity].[EmailOtpChallenges]
                 OUTPUT DELETED.[EmailOtpChallengeId]
                 WHERE [CreatedAtUtc] < :createdBefore AND [ExpiresAtUtc] < :now',
                max(1, $limit),
            ),
            [':createdBefore' => AzureSqlValueMapper::formatDateTime($createdBefore), ':now' => AzureSqlValueMapper::formatDateTime($nowUtc)],
        );

        return count($rows);
    }
}
