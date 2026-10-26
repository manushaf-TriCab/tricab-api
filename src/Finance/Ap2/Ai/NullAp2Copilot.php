<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2CopilotReply;
use TriCab\Core\Finance\Ap2\Contract\Ap2Copilot;

/** AI not connected: refuses (503 FINANCE_AP2_AI_UNAVAILABLE). */
final class NullAp2Copilot implements Ap2Copilot
{
    public function respond(array $context, string $message, array $history): Ap2CopilotReply
    {
        throw new Ap2AiUnavailable('Copilot is not connected yet');
    }
}
