<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Ai\Ap2CopilotReply;

/**
 * The AP assistant. Called by `POST /copilot`. It reads; it never writes: whatever it wants done comes back as a
 * proposal ({@see \TriCab\Core\Finance\Ap2\Ai\Ap2CopilotProposal}) that the web applies through the normal routes.
 *
 * `$context` is `{mailId?: string, supplierId?: string}` (ids already checked to exist). `$history` is the earlier
 * turns, oldest first: `[{role: 'user'|'assistant', content: string}]`, at most 20.
 * Throws {@see \TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable} when it cannot answer.
 */
interface Ap2Copilot
{
    /**
     * @param array{mailId?: string, supplierId?: string} $context
     * @param list<array{role: string, content: string}> $history
     */
    public function respond(array $context, string $message, array $history): Ap2CopilotReply;
}
