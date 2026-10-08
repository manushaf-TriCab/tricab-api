<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Jobs;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobClaims;

/**
 * `finance.job_runs` (`create-finance-jobs-schema.sql`). A claim is the row keyed by
 * `(job_name, slot)`: taking it is one `INSERT ... WHERE NOT EXISTS`, and the primary
 * key settles two runners that race past the check. A row still `running` after the
 * stale period is taken over with one `UPDATE` that names the old state, and each
 * writer then reads the row back: the token in it says who won.
 */
final class AzureSqlFinanceJobClaims implements FinanceJobClaims
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function claim(string $job, string $slot, DateTimeImmutable $now, int $staleAfterSeconds): ?string
    {
        $token = bin2hex(random_bytes(16));
        $at = AzureSqlValueMapper::formatDateTime($now);

        try {
            $this->connection->execute(
                'INSERT INTO [finance].[job_runs] ([job_name], [slot], [claim_token], [status], [claimed_at])
                 SELECT :job, :slot, :token, \'running\', :at
                 WHERE NOT EXISTS (SELECT 1 FROM [finance].[job_runs] WHERE [job_name] = :job2 AND [slot] = :slot2)',
                ['job' => $job, 'slot' => $slot, 'token' => $token, 'at' => $at, 'job2' => $job, 'slot2' => $slot],
            );
        } catch (DatabaseUnavailable) {
            // Raced: the row exists now. The read below says whether it is ours; a real outage fails there too.
        }
        if ($this->tokenOf($job, $slot) === $token) {
            return $token;
        }

        $cutoff = AzureSqlValueMapper::formatDateTime($now->modify('-' . $staleAfterSeconds . ' seconds'));
        $this->connection->execute(
            'UPDATE [finance].[job_runs] SET [claim_token] = :token, [claimed_at] = :at
             WHERE [job_name] = :job AND [slot] = :slot AND [status] = \'running\' AND [claimed_at] <= :cutoff',
            ['token' => $token, 'at' => $at, 'job' => $job, 'slot' => $slot, 'cutoff' => $cutoff],
        );

        return $this->tokenOf($job, $slot) === $token ? $token : null;
    }

    public function finish(string $job, string $slot, string $token, bool $succeeded, ?string $error, array $summary, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'UPDATE [finance].[job_runs]
             SET [status] = :status, [finished_at] = :at, [error_message] = :error, [summary_json] = :summary
             WHERE [job_name] = :job AND [slot] = :slot AND [claim_token] = :token',
            [
                'status' => $succeeded ? 'succeeded' : 'failed',
                'at' => AzureSqlValueMapper::formatDateTime($at),
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'job' => $job,
                'slot' => $slot,
                'token' => $token,
            ],
        );
    }

    private function tokenOf(string $job, string $slot): ?string
    {
        $rows = $this->connection->fetchAll(
            'SELECT [claim_token] FROM [finance].[job_runs] WHERE [job_name] = :job AND [slot] = :slot',
            ['job' => $job, 'slot' => $slot],
        );

        return $rows === [] ? null : trim(AzureSqlValueMapper::stringValue($rows[0]['claim_token']));
    }
}
