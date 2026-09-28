<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Persistence;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Rfq\Application\DTO\RfqDetail;
use TriCab\Core\Rfq\Application\DTO\RfqQueueQuery;
use TriCab\Core\Rfq\Application\DTO\RfqRecord;
use TriCab\Core\Rfq\Application\DTO\RfqSummary;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Domain\Service\ExtractionStatePolicy;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\RfqOwnership;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Storage\StoreFormat;
use TriCab\Core\Rfq\Storage\StoreProjection;

/**
 * The RFQ queue and record on Azure SQL, read from the store's tables
 * (`[rfq]`, the live MySQL shape, §6.5). Replaces `InMemoryRfqRepository` in
 * the azure-sql branch.
 *
 * ## No scope
 *
 * Everyone who reads RFQs reads all of them (`RfqAccessPolicy::canSee()`,
 * owner decision 28 Sep 2026). The query applies only the caller's filters,
 * which narrow; branch is one of them.
 *
 * ## People are matched by email, once
 *
 * The store names people by email (`created_by_email`, `assignee_email`), as
 * the source did. The `people` CTE maps a normalised address to exactly one
 * platform user: `identity.Users` primary addresses, plus verified, active
 * `identity.UserEmailAddresses`. An address claimed by two users maps to
 * nobody. The same CTE gives the record its owner and assignee, which decide
 * who may *work* it, and serves the `mine` filter, so the two cannot disagree.
 * An address that matches nobody makes the RFQ nobody's own: everyone still
 * sees it, and only a manager can work it until it is assigned.
 *
 * ## Not here yet
 *
 * The detail's revision (header and lines, Phase 4), reads (3), documents
 * (2.5/3) and sync attempts (5) join `findDetail` in their phases, as the
 * contract table says. Extraction coverage is not measured until plan 3.4, so
 * `ExtractionStatePolicy` is told so, and a settled RFQ reads `partial`.
 */
final class AzureSqlRfqRepository implements RfqRepository
{
    private const PEOPLE = <<<'SQL'
        WITH [addresses] AS (
            SELECT u.[NormalizedEmailAddress] AS [address], u.[UserId]
            FROM [identity].[Users] u
            UNION
            SELECT a.[NormalizedEmailAddress], a.[UserId]
            FROM [identity].[UserEmailAddresses] a
            WHERE a.[IsActive] = 1 AND a.[VerifiedAtUtc] IS NOT NULL
        ),
        [people] AS (
            SELECT [address], MIN([UserId]) AS [UserId]
            FROM [addresses]
            GROUP BY [address]
            HAVING COUNT(DISTINCT [UserId]) = 1
        )
        SQL;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function pageVisibleTo(RfqActor $actor, RfqQueueQuery $query): array
    {
        if (!$actor->can(Module::PERMISSION_DRAFT_READ)) {
            return ['items' => [], 'total' => 0];
        }
        $params = [];
        $where = ['1 = 1', ...$this->filterPredicates($actor, $query, $params)];
        $params['offset'] = ($query->page - 1) * $query->pageSize;
        $params['limit'] = $query->pageSize;

        $rows = $this->connection->fetchAll(
            self::PEOPLE . self::select() . ' WHERE ' . implode(' AND ', array_filter($where)) . '
             ORDER BY ' . self::priorityRank() . ', d.[created_at] DESC, d.[id]
             OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY',
            $params,
        );
        if ($rows === []) {
            // Past the last page the window is empty but the total is not.
            $total = $query->page > 1 ? $this->count($actor, $query) : 0;

            return ['items' => [], 'total' => $total];
        }

        return [
            'items' => $this->records($rows),
            'total' => AzureSqlValueMapper::intValue($rows[0]['total_rows'] ?? 0),
        ];
    }

    public function findRecord(string $publicId): ?RfqRecord
    {
        $draftId = StoreProjection::draftId($publicId);
        if ($draftId === null) {
            return null;
        }
        $rows = $this->connection->fetchAll(self::PEOPLE . self::select() . ' WHERE d.[id] = :id', ['id' => $draftId]);

        return $rows === [] ? null : $this->records($rows)[0];
    }

    public function branches(): array
    {
        $rows = $this->connection->fetchAll(
            "SELECT DISTINCT UPPER(LTRIM(RTRIM([branch]))) AS [branch]
             FROM [rfq].[rfq_draft_quotes]
             WHERE NULLIF(LTRIM(RTRIM([branch])), N'') IS NOT NULL
             ORDER BY 1",
        );

        return array_map(static fn (array $r): string => AzureSqlValueMapper::stringValue($r['branch']), $rows);
    }

    public function findDetail(RfqRecord $record, array $allowedActions): RfqDetail
    {
        return new RfqDetail(new RfqSummary($record, $allowedActions), null, [], [], []);
    }

    private function count(RfqActor $actor, RfqQueueQuery $query): int
    {
        $params = [];
        $where = ['1 = 1', ...$this->filterPredicates($actor, $query, $params)];
        $rows = $this->connection->fetchAll(
            self::PEOPLE . ' SELECT COUNT(*) AS [n] ' . self::from() . ' WHERE ' . implode(' AND ', array_filter($where)),
            $params,
        );

        return AzureSqlValueMapper::intValue($rows[0]['n'] ?? 0);
    }

    private static function select(): string
    {
        return '
            SELECT d.[id], d.[label], d.[source], d.[status], d.[priority], d.[due_at], d.[created_at], d.[updated_at],
                   d.[refno], d.[revision], d.[branch], d.[archived_at],
                   CASE WHEN ISJSON(d.[quote_details]) = 1 THEN JSON_VALUE(d.[quote_details], \'$.customer_code\') END AS [customer_code],
                   CASE WHEN ISJSON(d.[quote_details]) = 1 THEN JSON_VALUE(d.[quote_details], \'$.customer_name\') END AS [customer_name],
                   o.[UserId] AS [owner_user_id], ou.[DisplayName] AS [owner_name],
                   a.[UserId] AS [assignee_user_id], au.[DisplayName] AS [assignee_name],
                   (SELECT COUNT(*) FROM [rfq].[rfq_draft_rows] r WHERE r.[draft_id] = d.[id]) AS [line_count],
                   (SELECT TOP 1 s.[state] FROM [rfq].[rfq_sync_attempts] s WHERE s.[draft_id] = d.[id]
                     ORDER BY s.[created_at] DESC, s.[attempt_no] DESC, s.[id] DESC) AS [latest_sync_state],
                   COUNT(*) OVER () AS [total_rows]
            ' . self::from();
    }

    private static function from(): string
    {
        return '
            FROM [rfq].[rfq_draft_quotes] d
            LEFT JOIN [people] o ON o.[address] = LOWER(LTRIM(RTRIM(d.[created_by_email])))
            LEFT JOIN [identity].[Users] ou ON ou.[UserId] = o.[UserId]
            LEFT JOIN [people] a ON a.[address] = LOWER(LTRIM(RTRIM(d.[assignee_email])))
            LEFT JOIN [identity].[Users] au ON au.[UserId] = a.[UserId]';
    }

    /**
     * The queue filters, which narrow and never widen.
     *
     * @param array<string, scalar|null> $params
     *
     * @return list<string>
     */
    private function filterPredicates(RfqActor $actor, RfqQueueQuery $query, array &$params): array
    {
        $where = [];
        $archived = "NULLIF(LTRIM(RTRIM(d.[archived_at])), N'') IS NOT NULL";
        if ($query->archived === RfqQueueQuery::ARCHIVED_EXCLUDE) {
            $where[] = "NOT ({$archived})";
        } elseif ($query->archived === RfqQueueQuery::ARCHIVED_ONLY) {
            $where[] = $archived;
        }
        if ($query->mineOnly) {
            $params['mine_user'] = $actor->userId;
            $where[] = 'a.[UserId] = :mine_user';
        }
        if ($query->branches !== []) {
            $branch = 'UPPER(LTRIM(RTRIM(d.[branch])))';
            $codes = array_values(array_filter($query->branches, static fn (string $b): bool => $b !== RfqQueueQuery::BRANCH_NONE));
            $either = [];
            if ($codes !== []) {
                $either[] = "{$branch} IN (" . self::bindList('branch', $codes, $params) . ')';
            }
            if (in_array(RfqQueueQuery::BRANCH_NONE, $query->branches, true)) {
                $either[] = "NULLIF(LTRIM(RTRIM(d.[branch])), N'') IS NULL";
            }
            $where[] = '(' . implode(' OR ', $either) . ')';
        }
        if ($query->statuses !== []) {
            $where[] = self::workflowStatusSql() . ' IN (' . self::bindList('status', array_map(
                static fn (WorkflowStatus $s): string => $s->toString(),
                $query->statuses,
            ), $params) . ')';
        }
        if ($query->priorities !== []) {
            $where[] = self::prioritySql() . ' IN (' . self::bindList('priority', array_map(
                static fn (Priority $p): string => $p->toString(),
                $query->priorities,
            ), $params) . ')';
        }
        if ($query->search !== '') {
            // `!` escapes, not `\`: PDO's placeholder scanner reads a backslash
            // inside a quoted literal as an escape, never sees `'\'` close, and
            // then misses every placeholder after it.
            $params['search'] = '%' . strtr($query->search, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']) . '%';
            $haystack = [
                "N'RFQ-' + UPPER(LEFT(d.[id], 8))",
                'd.[label]',
                "CASE WHEN ISJSON(d.[quote_details]) = 1 THEN JSON_VALUE(d.[quote_details], '$.customer_code') END",
                "CASE WHEN ISJSON(d.[quote_details]) = 1 THEN JSON_VALUE(d.[quote_details], '$.customer_name') END",
                'd.[refno]',
            ];
            $where[] = '(' . implode(' OR ', array_map(
                static fn (string $column, int $i): string => "{$column} LIKE :search{$i} ESCAPE '!'",
                $haystack,
                array_keys($haystack),
            )) . ')';
            foreach (array_keys($haystack) as $i) {
                $params["search{$i}"] = $params['search'];
            }
            unset($params['search']);
        }

        return $where;
    }

    /** `StoreProjection::workflowStatus()` as SQL, generated from the same map. */
    private static function workflowStatusSql(): string
    {
        $status = "LOWER(LTRIM(RTRIM(ISNULL(d.[status], N''))))";
        $cases = ["WHEN {$status} = N'' THEN N'" . WorkflowStatus::SOURCE_STATUS_MAP['new'] . "'"];
        foreach (WorkflowStatus::SOURCE_STATUS_MAP as $source => $status_) {
            $cases[] = "WHEN {$status} = N'{$source}' THEN N'{$status_}'";
        }

        return 'CASE ' . implode(' ', $cases) . " ELSE N'" . WorkflowStatus::new()->toString() . "' END";
    }

    /** `Priority::fromStorage()` as SQL. */
    private static function prioritySql(): string
    {
        $priority = "LOWER(LTRIM(RTRIM(ISNULL(d.[priority], N''))))";
        $known = implode(', ', array_map(static fn (string $p): string => "N'{$p}'", Priority::values()));

        return "CASE WHEN {$priority} IN ({$known}) THEN {$priority} ELSE N'" . Priority::normal()->toString() . "' END";
    }

    /** `Priority::rank()`, most pressing first. */
    private static function priorityRank(): string
    {
        $cases = [];
        foreach (Priority::cases() as $priority) {
            $cases[] = "WHEN N'{$priority->toString()}' THEN {$priority->rank()}";
        }

        return 'CASE ' . self::prioritySql() . ' ' . implode(' ', $cases) . ' END';
    }

    /**
     * @param list<string>               $values
     * @param array<string, scalar|null> $params
     */
    private static function bindList(string $prefix, array $values, array &$params): string
    {
        $names = [];
        foreach ($values as $i => $value) {
            $params["{$prefix}{$i}"] = $value;
            $names[] = ":{$prefix}{$i}";
        }

        return implode(', ', $names);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<RfqRecord>
     */
    private function records(array $rows): array
    {
        $reads = $this->readsFor(array_map(static fn (array $r): string => AzureSqlValueMapper::stringValue($r['id']), $rows));
        $records = [];
        foreach ($rows as $row) {
            $id = AzureSqlValueMapper::stringValue($row['id']);
            $created = StoreFormat::parseTime(AzureSqlValueMapper::nullableString($row['created_at'])) ?? new DateTimeImmutable('@0');
            $records[] = new RfqRecord(
                publicId: StoreProjection::publicId($id),
                reference: StoreProjection::reference($id),
                title: is_string($row['label']) ? $row['label'] : '',
                customerCode: AzureSqlValueMapper::nullableString($row['customer_code']),
                customerName: AzureSqlValueMapper::nullableString($row['customer_name']),
                ownership: new RfqOwnership(
                    ownerUserId: AzureSqlValueMapper::nullableInt($row['owner_user_id']),
                    assigneeUserId: AzureSqlValueMapper::nullableInt($row['assignee_user_id']),
                    branchCode: AzureSqlValueMapper::nullableString($row['branch']),
                    archived: AzureSqlValueMapper::nullableString($row['archived_at']) !== null,
                ),
                ownerDisplayName: AzureSqlValueMapper::nullableString($row['owner_name']),
                assigneeDisplayName: AzureSqlValueMapper::nullableString($row['assignee_name']),
                workflowStatus: StoreProjection::workflowStatus($row['status']),
                priority: StoreProjection::priority($row['priority']),
                dueAtUtc: StoreFormat::parseTime(AzureSqlValueMapper::nullableString($row['due_at'])),
                channel: StoreProjection::channel($row['source']),
                extractionState: ExtractionStatePolicy::derive($reads[$id] ?? [], false),
                latestSyncState: $row['latest_sync_state'] === null ? null : StoreProjection::syncState($row['latest_sync_state']),
                quoteNumber: AzureSqlValueMapper::nullableString($row['refno']),
                lineCount: AzureSqlValueMapper::intValue($row['line_count']),
                revision: AzureSqlValueMapper::intValue($row['revision']) ?: 1,
                receivedAtUtc: $created,
                updatedAtUtc: StoreFormat::parseTime(AzureSqlValueMapper::nullableString($row['updated_at'])) ?? $created,
            );
        }

        return $records;
    }

    /**
     * Every read of each draft, for `ExtractionStatePolicy`.
     *
     * @param list<string> $draftIds
     *
     * @return array<string, list<ReaderJobStatus>>
     */
    private function readsFor(array $draftIds): array
    {
        if ($draftIds === []) {
            return [];
        }
        $params = [];
        $rows = $this->connection->fetchAll(
            'SELECT [draft_id], [status],
                    CASE WHEN NULLIF(LTRIM(RTRIM([proposal])), N\'\') IS NULL OR [proposal] IN (N\'{}\', N\'null\') THEN 0 ELSE 1 END AS [has_proposal]
             FROM [rfq].[rfq_reader_jobs] WHERE [draft_id] IN (' . self::bindList('draft', $draftIds, $params) . ')',
            $params,
        );
        $reads = [];
        foreach ($rows as $row) {
            $reads[AzureSqlValueMapper::stringValue($row['draft_id'])][] = StoreProjection::readerStatus($row['status'], AzureSqlValueMapper::boolValue($row['has_proposal']));
        }

        return $reads;
    }
}
