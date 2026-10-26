<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsageTotal;

/** The reading side of {@see Ap2AiUsageLog}: what the AI used and cost, so the cost per invoice can be measured. */
interface Ap2AiUsageReport
{
    /**
     * @return list<Ap2AiUsageTotal> one per purpose and model logged in `[$from, $toExclusive)`, by purpose then model;
     *                              `calls` counts model requests, `failed` the logged entries that did not succeed
     */
    public function totals(DateTimeImmutable $from, DateTimeImmutable $toExclusive): array;
}
