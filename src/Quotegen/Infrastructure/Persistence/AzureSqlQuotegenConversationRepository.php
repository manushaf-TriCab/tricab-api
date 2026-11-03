<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Infrastructure\Persistence;

use JsonException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Quotegen\Application\QuotegenChatService;
use TriCab\Api\Quotegen\Application\QuotegenConversation;
use TriCab\Api\Quotegen\Application\QuotegenConversationRepository;
use TriCab\Api\Quotegen\Application\QuotegenMessage;

final readonly class AzureSqlQuotegenConversationRepository implements QuotegenConversationRepository
{
    public function __construct(private SqlConnection $connection)
    {
    }

    public function conversationFor(string $rfqPublicId): QuotegenConversation
    {
        $rfq = strtolower($rfqPublicId);
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [rfq].[QuotegenConversations] ([PublicId], [RfqPublicId])
SELECT :publicId, :rfqPublicId
WHERE NOT EXISTS (SELECT 1 FROM [rfq].[QuotegenConversations] WHERE [RfqPublicId] = :existsRfqPublicId)
SQL,
            [':publicId' => QuotegenChatService::uuid(), ':rfqPublicId' => $rfq, ':existsRfqPublicId' => $rfq],
        );
        $rows = $this->connection->fetchAll(
            'SELECT [PublicId] FROM [rfq].[QuotegenConversations] WHERE [RfqPublicId] = :rfqPublicId',
            [':rfqPublicId' => $rfq],
        );

        return new QuotegenConversation(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''), $rfq);
    }

    public function messages(string $conversationId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT m.[PublicId], m.[Role], m.[AuthorUserPublicId], m.[AuthorName], m.[MessageText], m.[EventsJson],
       m.[ProposedSyncRevision], m.[ContainsPricing], CONVERT(NVARCHAR(30), m.[CreatedAtUtc], 126) AS [CreatedAt]
FROM [rfq].[QuotegenMessages] m
INNER JOIN [rfq].[QuotegenConversations] c ON c.[ConversationId] = m.[ConversationId]
WHERE c.[PublicId] = :conversationId
ORDER BY m.[MessageId]
SQL,
            [':conversationId' => $conversationId],
        );

        $messages = [];
        foreach ($rows as $row) {
            $events = [];
            try {
                $decoded = json_decode(AzureSqlValueMapper::stringValue($row['EventsJson'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = [];
            }
            foreach (is_array($decoded) ? $decoded : [] as $event) {
                if (is_array($event) && is_string($event['name'] ?? null)) {
                    $events[] = [
                        'kind' => is_string($event['kind'] ?? null) ? $event['kind'] : 'tool',
                        'name' => $event['name'],
                        'summary' => is_string($event['summary'] ?? null) ? $event['summary'] : '',
                    ];
                }
            }
            $revision = $row['ProposedSyncRevision'] ?? null;
            $messages[] = new QuotegenMessage(
                AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                AzureSqlValueMapper::stringValue($row['Role'] ?? 'event'),
                AzureSqlValueMapper::nullableString($row['AuthorUserPublicId'] ?? null),
                AzureSqlValueMapper::nullableString($row['AuthorName'] ?? null),
                AzureSqlValueMapper::stringValue($row['MessageText'] ?? ''),
                $events,
                $revision === null ? null : AzureSqlValueMapper::intValue($revision),
                AzureSqlValueMapper::stringValue($row['CreatedAt'] ?? '') . 'Z',
                AzureSqlValueMapper::intValue($row['ContainsPricing'] ?? 0) === 1,
            );
        }

        return $messages;
    }

    public function append(string $conversationId, QuotegenMessage $message): void
    {
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [rfq].[QuotegenMessages] (
    [PublicId], [ConversationId], [Role], [AuthorUserPublicId], [AuthorName], [MessageText],
    [EventsJson], [ProposedSyncRevision], [ContainsPricing]
)
SELECT :publicId, [ConversationId], :role, :authorUserPublicId, :authorName, :messageText,
       :eventsJson, :proposedSyncRevision, :containsPricing
FROM [rfq].[QuotegenConversations]
WHERE [PublicId] = :conversationId
SQL,
            [
                ':publicId' => $message->id,
                ':role' => $message->role,
                ':authorUserPublicId' => $message->authorUserId,
                ':authorName' => $message->authorName,
                ':messageText' => $message->text,
                ':eventsJson' => json_encode($message->events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':proposedSyncRevision' => $message->proposedSyncRevision,
                ':containsPricing' => $message->containsPricing ? 1 : 0,
                ':conversationId' => $conversationId,
            ],
        );
    }

    public function findOperation(string $conversationId, string $operationId): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT e.[ResultJson]
FROM [rfq].[QuotegenToolEvents] e
INNER JOIN [rfq].[QuotegenConversations] c ON c.[ConversationId] = e.[ConversationId]
WHERE c.[PublicId] = :conversationId AND e.[OperationId] = :operationId
SQL,
            [':conversationId' => $conversationId, ':operationId' => $operationId],
        );
        if ($rows === []) {
            return null;
        }
        try {
            $decoded = json_decode(AzureSqlValueMapper::stringValue($rows[0]['ResultJson'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        /** @var array<string, mixed>|null $result */
        $result = is_array($decoded) ? $decoded : null;

        return $result;
    }

    public function recordOperation(string $conversationId, string $operationId, string $toolName, string $status, string $summary, array $result): void
    {
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [rfq].[QuotegenToolEvents] ([ConversationId], [OperationId], [ToolName], [Status], [Summary], [ResultJson])
SELECT [ConversationId], :operationId, :toolName, :status, :summary, :resultJson
FROM [rfq].[QuotegenConversations]
WHERE [PublicId] = :conversationId
  AND NOT EXISTS (
      SELECT 1 FROM [rfq].[QuotegenToolEvents] e
      WHERE e.[ConversationId] = [rfq].[QuotegenConversations].[ConversationId] AND e.[OperationId] = :existsOperationId
  )
SQL,
            [
                ':operationId' => $operationId,
                ':toolName' => $toolName,
                ':status' => $status,
                ':summary' => mb_substr($summary, 0, 500),
                ':resultJson' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
                ':conversationId' => $conversationId,
                ':existsOperationId' => $operationId,
            ],
        );
    }
}
