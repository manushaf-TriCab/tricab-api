<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

interface QuotegenConversationRepository
{
    /** The RFQ's conversation, created on first use. */
    public function conversationFor(string $rfqPublicId): QuotegenConversation;

    /** @return list<QuotegenMessage> oldest first */
    public function messages(string $conversationId): array;

    public function append(string $conversationId, QuotegenMessage $message): void;

    /**
     * A write the assistant already completed this turn, by its operation id.
     *
     * @return array<string, mixed>|null the tool result as recorded
     */
    public function findOperation(string $conversationId, string $operationId): ?array;

    /** @param array<string, mixed> $result */
    public function recordOperation(string $conversationId, string $operationId, string $toolName, string $status, string $summary, array $result): void;
}
