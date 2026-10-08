<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Ai;

/**
 * Reaches the private `finance_ai` process. Contract `finance-ai.v1`
 * ({@see FinanceAiContract}).
 *
 * Like the RFQ parser's client, this **throws**: an answer of "nothing" must never be
 * the answer to "the service is not there". Every failure is a typed
 * {@see FinanceAiFailure}, and the caller decides what to tell the person.
 *
 * Implementations send the contract, the correlation id and the internal token, set
 * their socket timeout to the deadline plus {@see FinanceAiContract::TRANSPORT_GRACE_MS},
 * and never log the data, the answer or the token.
 */
interface FinanceAiClient
{
    /**
     * @param array<string, mixed> $data what the purpose takes; never a prompt
     * @param int|null $deadlineMs the deadline, within the contract's bounds; null for the default
     *
     * @throws FinanceAiFailure
     */
    public function complete(string $purpose, array $data, ?int $deadlineMs = null): FinanceAiResult;

    /** Whether the service answers `GET /v1/health` as `finance-ai.v1`. */
    public function isAlive(): bool;
}
