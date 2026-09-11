<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Contract\ExpenseReminderLogRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class AzureSqlExpenseReminderLogRepository implements ExpenseReminderLogRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function record(
        string $recipientEmail,
        ?UserId $recipientUserId,
        ?UserId $sentByUserId,
        DateTimeImmutable $sentAtUtc,
        ?string $legacySentBy = null,
    ): array {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [expense].[ExpenseReminderLog] (
    [RecipientUserId],
    [RecipientEmail],
    [SentByUserId],
    [LegacySentBy],
    [SentAtUtc]
)
OUTPUT
    INSERTED.[ExpenseReminderLogId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[RecipientEmail],
    CONVERT(VARCHAR(33), INSERTED.[SentAtUtc], 127) AS [SentAtUtc]
VALUES (
    :recipientUserId,
    :recipientEmail,
    :sentByUserId,
    :legacySentBy,
    :sentAtUtc
)
SQL,
            [
                ':recipientUserId' => $recipientUserId?->toInt(),
                ':recipientEmail' => trim($recipientEmail),
                ':sentByUserId' => $sentByUserId?->toInt(),
                ':legacySentBy' => $legacySentBy !== null && trim($legacySentBy) !== ''
                    ? trim($legacySentBy)
                    : null,
                ':sentAtUtc' => AzureSqlValueMapper::formatDateTime($sentAtUtc),
            ],
        );

        $row = $rows[0];

        return [
            'id' => AzureSqlValueMapper::intValue($row['ExpenseReminderLogId'] ?? 0),
            'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            'recipientEmail' => AzureSqlValueMapper::stringValue($row['RecipientEmail'] ?? ''),
            'sentAtUtc' => AzureSqlValueMapper::stringValue($row['SentAtUtc'] ?? ''),
        ];
    }

    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT TOP ({$limit})
    l.[ExpenseReminderLogId],
    CONVERT(NVARCHAR(36), l.[PublicId]) AS [PublicId],
    l.[RecipientEmail],
    l.[RecipientUserId],
    l.[SentByUserId],
    CONVERT(VARCHAR(33), l.[SentAtUtc], 127) AS [SentAtUtc]
FROM [expense].[ExpenseReminderLog] l
ORDER BY l.[ExpenseReminderLogId] DESC
SQL,
        );

        return array_map(static function (array $row): array {
            return [
                'id' => AzureSqlValueMapper::intValue($row['ExpenseReminderLogId'] ?? 0),
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'recipientEmail' => AzureSqlValueMapper::stringValue($row['RecipientEmail'] ?? ''),
                'recipientUserId' => AzureSqlValueMapper::nullableInt($row['RecipientUserId'] ?? null),
                'sentByUserId' => AzureSqlValueMapper::nullableInt($row['SentByUserId'] ?? null),
                'sentAtUtc' => AzureSqlValueMapper::stringValue($row['SentAtUtc'] ?? ''),
            ];
        }, $rows);
    }
}
