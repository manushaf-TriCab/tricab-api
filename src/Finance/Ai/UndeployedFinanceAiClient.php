<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ai;

use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Shared\Ai\FinanceAiResult;

/**
 * The client wherever the AI service is not switched on (`FINANCE_AI_ENABLED` with an
 * internal token): local runs without Python, tests, and production until an app needs it.
 *
 * Refuses every call as `unavailable`, never an empty answer, for the reason
 * {@see FinanceAiClient} gives.
 */
final class UndeployedFinanceAiClient implements FinanceAiClient
{
    public function complete(string $purpose, array $data, ?int $deadlineMs = null): FinanceAiResult
    {
        throw FinanceAiFailure::of(FinanceAiFailure::UNAVAILABLE, 'The finance AI service is not deployed on this platform yet.');
    }

    public function isAlive(): bool
    {
        return false;
    }
}
