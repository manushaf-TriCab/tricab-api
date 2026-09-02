<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantConversation;
use TriCab\Api\Assistant\Application\AssistantConversationRepository;
use TriCab\Api\Assistant\Application\AssistantConversationTurn;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;

final readonly class AzureSqlAssistantConversationRepository implements AssistantConversationRepository
{
    public function __construct(private SqlConnection $connection)
    {
    }

    public function getOrCreate(
        ?string $publicId,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): AssistantConversation {
        $normalizedRegion = self::normalizeRegion($regionCode);
        if ($publicId !== null) {
            $existing = $this->find($publicId);
            if (
                $existing !== null
                && $existing->regionCode === $normalizedRegion
                && $existing->catalogueVisibility === $catalogueVisibility->value
            ) {
                return $existing;
            }
        }

        $conversation = new AssistantConversation(
            self::uuid(),
            $normalizedRegion,
            $catalogueVisibility->value,
        );
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [product].[AssistantConversations] ([PublicId], [RegionCode], [CatalogueVisibility])
VALUES (:publicId, :regionCode, :catalogueVisibility)
SQL,
            [
                ':publicId' => $conversation->publicId,
                ':regionCode' => $conversation->regionCode,
                ':catalogueVisibility' => $conversation->catalogueVisibility,
            ],
        );

        return $conversation;
    }

    public function find(string $publicId): ?AssistantConversation
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [ConversationPublicId],
    c.[RegionCode],
    c.[CatalogueVisibility],
    t.[UserMessage],
    t.[AssistantMessage]
FROM [product].[AssistantConversations] c
OUTER APPLY (
    SELECT TOP (12)
        turn.[ConversationTurnId], turn.[UserMessage], turn.[AssistantMessage]
    FROM [product].[AssistantConversationTurns] turn
    WHERE turn.[ConversationId] = c.[ConversationId]
    ORDER BY turn.[ConversationTurnId] DESC
) t
WHERE c.[PublicId] = :publicId
ORDER BY t.[ConversationTurnId]
SQL,
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            return null;
        }

        $conversation = new AssistantConversation(
            AzureSqlValueMapper::stringValue($rows[0]['ConversationPublicId'] ?? null),
            AzureSqlValueMapper::nullableString($rows[0]['RegionCode'] ?? null),
            AzureSqlValueMapper::stringValue($rows[0]['CatalogueVisibility'] ?? 'public'),
        );
        foreach ($rows as $row) {
            if (!is_string($row['UserMessage'] ?? null) || !is_string($row['AssistantMessage'] ?? null)) {
                continue;
            }
            $conversation->append(new AssistantConversationTurn(
                userMessage: $row['UserMessage'],
                assistantMessage: $row['AssistantMessage'],
            ));
        }

        return $conversation;
    }

    public function appendTurn(AssistantConversation $conversation, AssistantConversationTurn $turn): void
    {
        // Distinct parameter names are intentional: pdo_sqlsrv rejects a named
        // parameter reused in more than one clause of the prepared batch.
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [product].[AssistantConversationTurns] (
    [ConversationId], [UserMessage], [AssistantMessage]
)
SELECT [ConversationId], :userMessage, :assistantMessage
FROM [product].[AssistantConversations]
WHERE [PublicId] = :turnConversationPublicId;

UPDATE [product].[AssistantConversations]
SET [UpdatedAtUtc] = SYSUTCDATETIME()
WHERE [PublicId] = :touchConversationPublicId;
SQL,
            [
                ':userMessage' => $turn->userMessage,
                ':assistantMessage' => $turn->assistantMessage,
                ':turnConversationPublicId' => $conversation->publicId,
                ':touchConversationPublicId' => $conversation->publicId,
            ],
        );
        $conversation->append($turn);
    }

    public function persistsAcrossRequests(): bool
    {
        return true;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private static function normalizeRegion(?string $regionCode): ?string
    {
        $normalized = $regionCode === null ? '' : strtoupper(trim($regionCode));

        return $normalized === '' ? null : $normalized;
    }
}
