<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsDraft;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsEvent;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsReview;
use TriCab\Core\Rfq\Application\Service\RfqAnalyticsService;
use TriCab\Core\Rfq\Contract\RfqAnalyticsSource;
use TriCab\Core\Rfq\Storage\StoreFormat;

/**
 * Reads the analytics facts from the `[rfq]` tables. Timestamps are text in
 * the store (two shapes, see `StoreFormat::parseTime`), so they are parsed
 * here and every window is applied by the service, never by comparing text.
 * Entry counts are aggregated per review session in SQL; the
 * "unchanged" rule is tc-web's `quality_metrics` predicate.
 */
final class AzureSqlRfqAnalyticsSource implements RfqAnalyticsSource
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function drafts(): array
    {
        $rows = $this->connection->fetchAll(
            "SELECT [id], [created_at], [status], [archived_at], [synced_at], [due_at], [assignee_email], [source], [branch],
                    CASE WHEN ISJSON([email_meta]) = 1 THEN JSON_VALUE([email_meta], '$.owner_branch') END AS [owner_branch]
             FROM [rfq].[rfq_draft_quotes]",
        );

        return array_map(static function (array $row): AnalyticsDraft {
            $branch = StoreFormat::branch($row['branch']);

            return new AnalyticsDraft(
                AzureSqlValueMapper::stringValue($row['id']),
                StoreFormat::parseTime(AzureSqlValueMapper::nullableString($row['created_at'])),
                self::status($row['status']),
                trim((string) AzureSqlValueMapper::nullableString($row['archived_at'])) !== '',
                StoreFormat::parseTime(AzureSqlValueMapper::nullableString($row['synced_at'])),
                StoreFormat::parseTime(AzureSqlValueMapper::nullableString($row['due_at'])),
                StoreFormat::email($row['assignee_email']),
                trim((string) AzureSqlValueMapper::nullableString($row['source'])),
                $branch !== '' ? $branch : StoreFormat::branch($row['owner_branch']),
            );
        }, $rows);
    }

    public function events(): array
    {
        $params = [];
        $types = [];
        foreach (AnalyticsEvent::TYPES as $i => $type) {
            $params["type{$i}"] = $type;
            $types[] = ":type{$i}";
        }
        $rows = $this->connection->fetchAll(
            'SELECT [draft_id], [event_type], [created_at] FROM [rfq].[rfq_draft_activity]
             WHERE [event_type] IN (' . implode(', ', $types) . ')',
            $params,
        );

        $events = [];
        foreach ($rows as $row) {
            $at = StoreFormat::parseTime(AzureSqlValueMapper::nullableString($row['created_at']));
            if ($at !== null) {
                $events[] = new AnalyticsEvent(
                    AzureSqlValueMapper::stringValue($row['draft_id']),
                    AzureSqlValueMapper::stringValue($row['event_type']),
                    $at,
                );
            }
        }

        return $events;
    }

    public function reviews(): array
    {
        $high = RfqAnalyticsService::CONFIDENCE_HIGH;
        $medium = RfqAnalyticsService::CONFIDENCE_MEDIUM;
        $rows = $this->connection->fetchAll(
            "SELECT s.[id], s.[draft_id], s.[created_at], s.[legacy_unlinked],
                    COUNT(e.[id]) AS [entries],
                    SUM(CASE WHEN e.[id] IS NOT NULL
                              AND ISNULL(e.[final_product_code], N'') = ISNULL(e.[system_product_code], N'')
                              AND ISNULL(e.[final_status], N'') = ISNULL(e.[system_status], N'')
                              AND LTRIM(RTRIM(ISNULL(e.[correction_reason], N''))) = N''
                             THEN 1 ELSE 0 END) AS [unchanged],
                    SUM(CASE WHEN LTRIM(RTRIM(ISNULL(e.[correction_reason], N''))) <> N'' THEN 1 ELSE 0 END) AS [with_reason],
                    SUM(CASE WHEN e.[system_confidence] >= {$high} THEN 1 ELSE 0 END) AS [high],
                    SUM(CASE WHEN e.[system_confidence] >= {$medium} AND e.[system_confidence] < {$high} THEN 1 ELSE 0 END) AS [medium],
                    SUM(CASE WHEN e.[system_confidence] < {$medium} THEN 1 ELSE 0 END) AS [low],
                    SUM(CASE WHEN e.[id] IS NOT NULL AND e.[system_confidence] IS NULL THEN 1 ELSE 0 END) AS [unknown]
             FROM [rfq].[rfq_sessions] s
             LEFT JOIN [rfq].[rfq_entries] e ON e.[session_id] = s.[id]
             GROUP BY s.[id], s.[draft_id], s.[created_at], s.[legacy_unlinked]",
        );
        $reasons = [];
        foreach ($this->connection->fetchAll(
            "SELECT [session_id], LTRIM(RTRIM([correction_reason])) AS [reason], COUNT(*) AS [n]
             FROM [rfq].[rfq_entries]
             WHERE LTRIM(RTRIM(ISNULL([correction_reason], N''))) <> N''
             GROUP BY [session_id], LTRIM(RTRIM([correction_reason]))",
        ) as $row) {
            $reasons[AzureSqlValueMapper::stringValue($row['session_id'])][AzureSqlValueMapper::stringValue($row['reason'])]
                = AzureSqlValueMapper::intValue($row['n']);
        }

        return array_map(static function (array $row) use ($reasons): AnalyticsReview {
            $id = AzureSqlValueMapper::stringValue($row['id']);
            $count = static fn (string $key): int => AzureSqlValueMapper::intValue($row[$key] ?? 0);

            return new AnalyticsReview(
                $id,
                trim((string) AzureSqlValueMapper::nullableString($row['draft_id'])),
                StoreFormat::parseTime(AzureSqlValueMapper::nullableString($row['created_at'])),
                AzureSqlValueMapper::intValue($row['legacy_unlinked']) !== 0,
                $count('entries'),
                $count('unchanged'),
                $count('with_reason'),
                $count('high'),
                $count('medium'),
                $count('low'),
                $count('unknown'),
                $reasons[$id] ?? [],
            );
        }, $rows);
    }

    public function people(array $emails): array
    {
        if ($emails === []) {
            return [];
        }
        $params = [];
        $marks = [];
        foreach ($emails as $i => $email) {
            $params["email{$i}"] = $email;
            $marks[] = ":email{$i}";
        }
        $rows = $this->connection->fetchAll(
            AzureSqlRfqRepository::PEOPLE . ' SELECT p.[address], u.[UserId], u.[DisplayName] FROM [people] p
             INNER JOIN [identity].[Users] u ON u.[UserId] = p.[UserId]
             WHERE p.[address] IN (' . implode(', ', $marks) . ')',
            $params,
        );

        $people = [];
        foreach ($rows as $row) {
            $name = trim((string) AzureSqlValueMapper::nullableString($row['DisplayName']));
            if ($name !== '') {
                $people[AzureSqlValueMapper::stringValue($row['address'])] = [
                    'userId' => AzureSqlValueMapper::intValue($row['UserId']),
                    'name' => $name,
                ];
            }
        }

        return $people;
    }

    /** tc-web `_normalise_status`: blank is `new`; `pending` and `push_failed` are old spellings. */
    private static function status(mixed $value): string
    {
        $status = strtolower(trim((string) AzureSqlValueMapper::nullableString($value)));
        if ($status === '') {
            return 'new';
        }

        return ['pending' => 'new', 'push_failed' => 'failed'][$status] ?? $status;
    }
}
