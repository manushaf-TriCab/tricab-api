<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;
use TriCab\Core\Finance\Ap2\Contract\Ap2AiUsageLog;

final class InMemoryAp2AiUsageLog implements Ap2AiUsageLog
{
    /** @var list<Ap2AiUsage> */
    public array $records = [];

    public function record(Ap2AiUsage $usage): void
    {
        $this->records[] = $usage;
    }

    public function totalCostUsd(): float
    {
        return round(array_sum(array_map(static fn (Ap2AiUsage $u): float => $u->costUsd, $this->records)), 6);
    }
}
