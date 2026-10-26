<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;
use TriCab\Core\Finance\Ap2\Contract\Ap2AiUsageLog;

/**
 * One line per AI call to the App Service log stream (as the assistant's turn trace does): purpose, model, prompt,
 * tokens, cost in USD, time and outcome. No mail text, no ids. `grep "Tri-AP2 AI usage"` and sum `costUsd` for the
 * cost per invoice until a table is wanted.
 */
final class ErrorLogAp2AiUsageLog implements Ap2AiUsageLog
{
    public function record(Ap2AiUsage $usage): void
    {
        $line = json_encode($usage->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        error_log('Tri-AP2 AI usage ' . ($line === false ? $usage->purpose : $line));
    }
}
