<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Infrastructure\Persistence;

use TriCab\Api\Quotegen\Application\QuotegenChatService;
use TriCab\Api\Quotegen\Application\QuotegenConversation;
use TriCab\Api\Quotegen\Application\QuotegenConversationRepository;
use TriCab\Api\Quotegen\Application\QuotegenMessage;

/** For tests and local runs: lives as long as the object. */
final class InMemoryQuotegenConversationRepository implements QuotegenConversationRepository
{
    /** @var array<string, QuotegenConversation> by RFQ public id */
    private array $byRfq = [];

    /** @var array<string, list<QuotegenMessage>> */
    private array $messages = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $operations = [];

    public function conversationFor(string $rfqPublicId): QuotegenConversation
    {
        $key = strtolower($rfqPublicId);

        return $this->byRfq[$key] ??= new QuotegenConversation(QuotegenChatService::uuid(), $key);
    }

    public function messages(string $conversationId): array
    {
        return $this->messages[$conversationId] ?? [];
    }

    public function append(string $conversationId, QuotegenMessage $message): void
    {
        $this->messages[$conversationId][] = $message;
    }

    public function findOperation(string $conversationId, string $operationId): ?array
    {
        return $this->operations[$conversationId][$operationId] ?? null;
    }

    public function recordOperation(string $conversationId, string $operationId, string $toolName, string $status, string $summary, array $result): void
    {
        $this->operations[$conversationId][$operationId] ??= $result;
    }
}
