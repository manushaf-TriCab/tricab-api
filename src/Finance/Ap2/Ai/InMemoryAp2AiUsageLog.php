<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsageTotal;
use TriCab\Core\Finance\Ap2\Contract\Ap2AiUsageLog;
use TriCab\Core\Finance\Ap2\Contract\Ap2AiUsageReport;

final class InMemoryAp2AiUsageLog implements Ap2AiUsageLog, Ap2AiUsageReport
{
    /** @var list<Ap2AiUsage> */
    public array $records = [];

    /** @var list<DateTimeImmutable> when each record was made, in step with `$records` */
    private array $times = [];

    public function record(Ap2AiUsage $usage): void
    {
        $this->records[] = $usage;
        $this->times[] = new DateTimeImmutable();
    }

    /** A record made at a given moment, for the period tests. */
    public function recordAt(Ap2AiUsage $usage, DateTimeImmutable $at): void
    {
        $this->records[] = $usage;
        $this->times[] = $at;
    }

    public function totalCostUsd(): float
    {
        return round(array_sum(array_map(static fn (Ap2AiUsage $u): float => $u->costUsd, $this->records)), 6);
    }

    public function totals(DateTimeImmutable $from, DateTimeImmutable $toExclusive): array
    {
        $groups = [];
        foreach ($this->records as $i => $u) {
            $at = $this->times[$i];
            if ($at < $from || $at >= $toExclusive) {
                continue;
            }
            $key = $u->purpose . "\0" . $u->model;
            $t = $groups[$key] ?? new Ap2AiUsageTotal($u->purpose, $u->model, 0, 0, 0, 0, 0, 0, 0.0, 0);
            $groups[$key] = new Ap2AiUsageTotal(
                $t->purpose,
                $t->model,
                $t->calls + $u->calls,
                $t->failed + ($u->ok ? 0 : 1),
                $t->inputTokens + $u->inputTokens,
                $t->outputTokens + $u->outputTokens,
                $t->cacheReadTokens + $u->cacheReadTokens,
                $t->cacheWriteTokens + $u->cacheWriteTokens,
                round($t->costUsd + $u->costUsd, 6),
                $t->durationMs + $u->durationMs,
            );
        }
        ksort($groups);

        return array_values($groups);
    }
}
