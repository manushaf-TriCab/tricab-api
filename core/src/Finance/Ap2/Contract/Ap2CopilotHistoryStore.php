<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2CopilotMessage;

/**
 * `finance.ap2_copilot_messages`: a person's Copilot conversations. A conversation is one person plus one open email
 * (`$mailId`; a `$supplierId` beside it is ignored for the key) or, with no email, one person plus one supplier. With
 * neither id there is no conversation: nothing is stored and nothing is read.
 */
interface Ap2CopilotHistoryStore
{
    /**
     * @param 'user'|'assistant' $role
     * @param list<array<string, mixed>>|null $proposals
     */
    public function append(int $userId, ?string $mailId, ?string $supplierId, string $role, string $message, ?array $proposals = null): void;

    /** @return list<Ap2CopilotMessage> the conversation's newest `$limit` messages, oldest first */
    public function recent(int $userId, ?string $mailId, ?string $supplierId, int $limit): array;

    /** Keeps only the newest `$keep` messages of the conversation. */
    public function prune(int $userId, ?string $mailId, ?string $supplierId, int $keep): void;
}
