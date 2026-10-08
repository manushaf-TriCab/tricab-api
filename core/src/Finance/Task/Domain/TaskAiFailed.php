<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/**
 * A drafting call did not produce a draft. The source's 502: "The AI draft failed: <reason>". The
 * reason is the finance AI service's own safe message (no data, no prompt, no provider text).
 * Never an empty draft.
 */
final class TaskAiFailed extends TaskFailure
{
    public function httpStatus(): int
    {
        return 502;
    }

    public function errorCode(): string
    {
        return 'FINANCE_TASK_AI_FAILED';
    }
}
