<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;

/** Where each AI call's tokens and cost go. Logging must never fail the call it measures. */
interface Ap2AiUsageLog
{
    public function record(Ap2AiUsage $usage): void;
}
