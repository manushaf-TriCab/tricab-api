<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;

/**
 * An AI feature was asked for but no AI is connected (or the model could not answer). Routes answer 503
 * `FINANCE_AP2_AI_UNAVAILABLE`; nothing is written. A real implementation may throw this too, when its model fails.
 */
final class Ap2AiUnavailable extends Ap2Failure
{
    public function httpStatus(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP2_AI_UNAVAILABLE';
    }
}
