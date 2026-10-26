<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use TriCab\Core\Finance\Ap2\Ai\Ap2CopilotReply;
use TriCab\Core\Finance\Ap2\Contract\Ap2Copilot;

/** A Copilot without a model: answers `$next` (or a fixed line) and records what it was asked. */
final class FakeAp2Copilot implements Ap2Copilot
{
    public ?Ap2CopilotReply $next = null;

    public ?\Throwable $failWith = null;

    /** @var list<array{context: array{mailId?: string, supplierId?: string}, message: string, history: int}> */
    public array $calls = [];

    public function respond(array $context, string $message, array $history): Ap2CopilotReply
    {
        $this->calls[] = ['context' => $context, 'message' => $message, 'history' => count($history)];
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->next ?? new Ap2CopilotReply('The AI Copilot is not connected here.');
    }
}
