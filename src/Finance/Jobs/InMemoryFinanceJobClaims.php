<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Jobs;

use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobClaims;

/** The claims held in memory, with the same rules as `finance.job_runs`: the tests', and a database-less run's. */
final class InMemoryFinanceJobClaims implements FinanceJobClaims
{
    /** @var array<string, array{token: string, status: string, claimedAt: DateTimeImmutable, error: ?string, summary: array<string, scalar|null>}> */
    private array $rows = [];

    private int $tokens = 0;

    public function claim(string $job, string $slot, DateTimeImmutable $now, int $staleAfterSeconds): ?string
    {
        $key = $job . "\0" . $slot;
        $row = $this->rows[$key] ?? null;
        if ($row !== null && !($row['status'] === 'running' && $now->getTimestamp() - $row['claimedAt']->getTimestamp() >= $staleAfterSeconds)) {
            return null;
        }
        $token = sprintf('%032x', ++$this->tokens);
        $this->rows[$key] = ['token' => $token, 'status' => 'running', 'claimedAt' => $now, 'error' => null, 'summary' => []];

        return $token;
    }

    public function finish(string $job, string $slot, string $token, bool $succeeded, ?string $error, array $summary, DateTimeImmutable $at): void
    {
        $key = $job . "\0" . $slot;
        if (($this->rows[$key]['token'] ?? null) !== $token) {
            return;
        }
        $this->rows[$key]['status'] = $succeeded ? 'succeeded' : 'failed';
        $this->rows[$key]['error'] = $error;
        $this->rows[$key]['summary'] = $summary;
    }

    /** @return array{status: string, error: ?string, summary: array<string, scalar|null>}|null */
    public function row(string $job, string $slot): ?array
    {
        $row = $this->rows[$job . "\0" . $slot] ?? null;

        return $row === null ? null : ['status' => $row['status'], 'error' => $row['error'], 'summary' => $row['summary']];
    }
}
