<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Persistence;

use Closure;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\DraftChangeLog;
use TriCab\Core\Rfq\Storage\DraftRowSet;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\PythonJson;
use TriCab\Core\Rfq\Storage\PythonText;
use TriCab\Core\Rfq\Storage\SalesRepAttribution;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Rfq\Storage\StoreFormat;
use TriCab\Core\Shared\Time\Clock;

/**
 * The RFQ draft store on Azure SQL: `[rfq].[rfq_draft_quotes]`, `_rows`,
 * `_row_edits` and `_activity`, in the live MySQL store's shape
 * (`create-rfq-schema.sql`, architecture doc §6.5).
 *
 * The source's `save_draft`, `update_draft` and `get_draft`, statement for
 * statement, with the line and change-log rules in {@see DraftRowSet}.
 *
 * ## Concurrency
 *
 * A content save reads the draft `WITH (UPDLOCK, HOLDLOCK, ROWLOCK)` inside
 * its transaction, then checks the revision. A second save of the same draft
 * waits on that lock, reads the new revision and gets
 * {@see RfqRevisionConflict}: last-writer-wins cannot happen, and a waiting
 * save never writes over lines it did not see. The source's `lock_row` is the
 * same idea (`SELECT … FOR UPDATE` on MySQL).
 *
 * Lines are inserted in chunks: SQL Server takes at most 2,100 parameters a
 * statement, and a 500-line quote is 3,500 values.
 */
final class AzureSqlRfqDraftStore implements RfqDraftStore
{
    use AzureSqlTransaction;

    private const ROW_CHUNK = 250;
    private const EDIT_CHUNK = 150;

    /** @var Closure(): string */
    private readonly Closure $ids;

    /**
     * @param (callable(): string)|null $ids new 32-hex ids; `StoreFormat::newId` unless a test fixes them
     */
    public function __construct(
        private readonly SqlConnection $connection,
        private readonly Clock $clock,
        ?callable $ids = null,
    ) {
        $this->ids = Closure::fromCallable($ids ?? StoreFormat::newId(...));
    }

    public function create(NewDraft $draft): string
    {
        $ids = $this->ids;
        $draftId = $ids();
        $now = $this->clock->now();
        $createdAt = StoreFormat::isoTime($now);
        $dueAt = StoreFormat::isoTime($now->modify('+' . StoreFormat::DEFAULT_DUE_HOURS . ' hours'));
        $owner = $draft->owner();
        $branch = $draft->ownerBranch();
        $assignee = $draft->assignee();
        $meta = $draft->emailMeta ?? [];

        $this->runInTransaction($this->connection, function () use ($draft, $draftId, $createdAt, $dueAt, $owner, $branch, $assignee, $meta): void {
            $this->connection->execute(
                'INSERT INTO [rfq].[rfq_draft_quotes]
                    ([id], [created_at], [updated_at], [source], [label], [status], [quote_details],
                     [email_meta], [created_by_email], [branch], [assignee_email], [priority], [due_at], [revision])
                 VALUES (:id, :created_at, :updated_at, :source, :label, N\'new\', :quote_details,
                     :email_meta, :created_by_email, :branch, :assignee_email, N\'normal\', :due_at, 1)',
                [
                    'id' => $draftId,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'source' => $draft->source,
                    'label' => $draft->label,
                    'quote_details' => PythonJson::encode($draft->quoteDetails === [] ? new \stdClass() : $draft->quoteDetails),
                    'email_meta' => $meta !== [] ? PythonJson::encode($meta) : null,
                    'created_by_email' => $owner !== '' ? $owner : null,
                    'branch' => $branch !== '' ? $branch : null,
                    'assignee_email' => $assignee !== '' ? $assignee : null,
                    'due_at' => $dueAt,
                ],
            );
            $set = DraftRowSet::replace($draftId, [], $draft->rows, true, null, null, '', $this->ids, $this->nowIso(...));
            $this->insertRows($set['rows']);
            $this->addActivity($draftId, 'created', null, [
                'source' => $draft->source,
                'assignee_email' => $assignee,
                'branch' => $branch,
            ], 1);
        });

        return $draftId;
    }

    public function saveContent(
        string $draftId,
        int $expectedRevision,
        array $quoteDetails,
        array $rows,
        string $nextStatus,
        DraftActor $actor,
        string $viewMode = '',
    ): ?int {
        if (!in_array($nextStatus, StoreFormat::STATUSES, true)) {
            throw InvalidRfqValue::forOneOf('draft status', $nextStatus, StoreFormat::STATUSES);
        }

        return $this->runInTransaction($this->connection, function () use ($draftId, $expectedRevision, $quoteDetails, $rows, $nextStatus, $actor, $viewMode): ?int {
            $current = $this->connection->fetchAll(
                'SELECT [sync_started_at], [status], [revision], [archived_at], [quote_details], [assignee_email]
                 FROM [rfq].[rfq_draft_quotes] WITH (UPDLOCK, HOLDLOCK, ROWLOCK)
                 WHERE [id] = :id',
                ['id' => $draftId],
            )[0] ?? null;
            if ($current === null) {
                return null;
            }
            $revision = AzureSqlValueMapper::intValue($current['revision'] ?? 1) ?: 1;
            if ($expectedRevision !== $revision) {
                throw new RfqRevisionConflict($revision, $expectedRevision);
            }
            if (DraftChangeLog::truthy($current['sync_started_at'])) {
                throw RfqDraftNotEditable::syncRunning();
            }
            if (DraftChangeLog::truthy($current['archived_at'])) {
                throw RfqDraftNotEditable::archived();
            }

            // The worker becomes the sales rep: assignee and 4js author.
            $rep = SalesRepAttribution::apply($quoteDetails, self::text($current['assignee_email']), $actor, $actor->operatorCode);
            $quoteDetails = $rep['quoteDetails'];
            $headerChanges = DraftChangeLog::headerChanges(PythonJson::decodeObject(self::text($current['quote_details'])), $quoteDetails);
            $nextRevision = $revision + 1;
            $this->connection->execute(
                'UPDATE [rfq].[rfq_draft_quotes]
                 SET [quote_details] = :quote_details, [status] = :status, [push_error] = NULL,
                     [updated_at] = :updated_at, [revision] = :revision, [assignee_email] = :assignee
                 WHERE [id] = :id',
                [
                    'quote_details' => PythonJson::encode($quoteDetails === [] ? new \stdClass() : $quoteDetails),
                    'status' => $nextStatus,
                    'updated_at' => $this->nowIso(),
                    'revision' => $nextRevision,
                    'assignee' => $rep['assignee'],
                    'id' => $draftId,
                ],
            );

            $previous = $this->connection->fetchAll(
                'SELECT [line_uid], [row_data], [system_row_data], [system_snapshot_valid]
                 FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = :id ORDER BY [row_order]',
                ['id' => $draftId],
            );
            $this->connection->execute('DELETE FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = :id', ['id' => $draftId]);
            $set = DraftRowSet::replace(
                $draftId,
                array_map(static fn (array $r): array => [
                    'line_uid' => self::text($r['line_uid']),
                    'row_data' => self::text($r['row_data']),
                    'system_row_data' => self::text($r['system_row_data']),
                    'system_snapshot_valid' => AzureSqlValueMapper::intValue($r['system_snapshot_valid']),
                ], $previous),
                $rows,
                false,
                $actor,
                $nextRevision,
                $viewMode,
                $this->ids,
                $this->nowIso(...),
            );
            $this->insertRows($set['rows']);
            $this->insertEdits($set['edits']);

            $detail = [
                'from_status' => self::normaliseStatus($current['status']),
                'to_status' => $nextStatus,
            ];
            if ($headerChanges !== []) {
                $detail['header_changes'] = $headerChanges;
            }
            if ($rep['assigneeChange'] !== null) {
                $detail['assignee'] = $rep['assigneeChange'];
            }
            if ($rep['authorSource'] !== null) {
                $detail['author_source'] = $rep['authorSource'];
            }
            $this->addActivity($draftId, 'content_updated', $actor, $detail, $nextRevision);

            return $nextRevision;
        });
    }

    public function load(string $draftId): ?StoredDraft
    {
        if (!StoreFormat::isId($draftId)) {
            return null;
        }
        $draft = $this->connection->fetchAll('SELECT * FROM [rfq].[rfq_draft_quotes] WHERE [id] = :id', ['id' => $draftId])[0] ?? null;
        if ($draft === null) {
            return null;
        }
        $rows = $this->connection->fetchAll(
            'SELECT [id], [row_data], [system_row_data], [system_snapshot_valid]
             FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = :id ORDER BY [row_order]',
            ['id' => $draftId],
        );

        return new StoredDraft(
            id: (string) self::text($draft['id']),
            revision: AzureSqlValueMapper::intValue($draft['revision']),
            status: self::normaliseStatus($draft['status']),
            columns: $draft,
            quoteDetails: PythonJson::decodeObject(self::text($draft['quote_details'])),
            emailMeta: PythonJson::decodeObject(self::text($draft['email_meta'])),
            rows: array_map(static fn (array $r): array => PythonJson::decodeObject(self::text($r['row_data'])) + [
                '_draft_row_id' => (string) self::text($r['id']),
                '_system_snapshot_valid' => AzureSqlValueMapper::boolValue($r['system_snapshot_valid']),
            ], $rows),
            systemRows: array_map(static fn (array $r): array => PythonJson::decodeObject(self::text($r['system_row_data'])), $rows),
        );
    }

    /**
     * A text column exactly as stored. Not `AzureSqlValueMapper::stringValue`,
     * which trims: these columns hold JSON and source text byte for byte.
     */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }

    private function nowIso(): string
    {
        return StoreFormat::isoTime($this->clock->now());
    }

    /**
     * The source's `_normalise_status`: empty reads as `new`, and two names
     * from before the statuses were renamed fold into their current ones.
     * Anything else passes through as it is.
     */
    private static function normaliseStatus(mixed $status): string
    {
        $status = strtolower(PythonText::strip(PythonText::str(DraftChangeLog::truthy($status) ? $status : 'new')));

        return ['pending' => 'new', 'push_failed' => 'failed'][$status] ?? $status;
    }

    /**
     * @param array<string, mixed>|null $detail
     */
    private function addActivity(string $draftId, string $eventType, ?DraftActor $actor, ?array $detail, ?int $revision, string $message = ''): void
    {
        $ids = $this->ids;
        $this->connection->execute(
            'INSERT INTO [rfq].[rfq_draft_activity]
                ([id], [draft_id], [created_at], [actor_email], [actor_role], [actor_branch], [event_type], [message], [detail], [revision])
             VALUES (:id, :draft_id, :created_at, :actor_email, :actor_role, :actor_branch, :event_type, :message, :detail, :revision)',
            [
                'id' => $ids(),
                'draft_id' => $draftId,
                'created_at' => $this->nowIso(),
                'actor_email' => $actor?->email,
                'actor_role' => $actor?->role,
                'actor_branch' => $actor?->branch,
                'event_type' => $eventType,
                'message' => $message !== '' ? $message : null,
                'detail' => $detail !== null && $detail !== [] ? PythonJson::encode($detail) : null,
                'revision' => $revision,
            ],
        );
    }

    /**
     * @param list<array{id: string, draft_id: string, row_order: int, row_data: string, system_row_data: ?string, system_snapshot_valid: int, line_uid: string}> $rows
     */
    private function insertRows(array $rows): void
    {
        foreach (array_chunk($rows, self::ROW_CHUNK) as $chunk) {
            $this->insertMany(
                '[rfq].[rfq_draft_rows]',
                ['id', 'draft_id', 'row_order', 'row_data', 'system_row_data', 'system_snapshot_valid', 'line_uid'],
                $chunk,
            );
        }
    }

    /**
     * @param list<array<string, scalar|null>> $edits
     */
    private function insertEdits(array $edits): void
    {
        foreach (array_chunk($edits, self::EDIT_CHUNK) as $chunk) {
            $this->insertMany(
                '[rfq].[rfq_draft_row_edits]',
                ['id', 'draft_id', 'line_uid', 'row_order', 'revision', 'created_at', 'actor_email',
                    'actor_role', 'view_mode', 'field', 'old_value', 'new_value', 'from_system'],
                $chunk,
            );
        }
    }

    /**
     * @param list<string>                     $columns
     * @param list<array<string, scalar|null>> $records
     */
    private function insertMany(string $table, array $columns, array $records): void
    {
        if ($records === []) {
            return;
        }
        $tuples = [];
        $params = [];
        foreach ($records as $i => $record) {
            $names = [];
            foreach ($columns as $j => $column) {
                $name = "p{$i}_{$j}";
                $names[] = ':' . $name;
                $params[$name] = $record[$column];
            }
            $tuples[] = '(' . implode(', ', $names) . ')';
        }
        $this->connection->execute(
            "INSERT INTO {$table} (" . implode(', ', array_map(static fn (string $c): string => "[{$c}]", $columns)) . ') VALUES ' . implode(', ', $tuples),
            $params,
        );
    }
}
