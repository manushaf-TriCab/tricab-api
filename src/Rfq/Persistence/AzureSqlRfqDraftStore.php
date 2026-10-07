<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Persistence;

use Closure;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Application\DTO\SyncAttemptView;
use TriCab\Core\Rfq\Contract\RfqReaderJobStore;
use TriCab\Core\Rfq\Contract\RfqSyncStore;
use TriCab\Core\Rfq\Contract\RfqWorkflowStore;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncNeedsReconciliation;
use TriCab\Core\Rfq\Domain\Service\SyncAttemptTransitionPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Domain\ValueObject\SyncHeaderState;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\DraftChangeLog;
use TriCab\Core\Rfq\Storage\DraftRowSet;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\PythonJson;
use TriCab\Core\Rfq\Storage\PythonText;
use TriCab\Core\Rfq\Storage\SalesRepAttribution;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Rfq\Storage\StoredReaderJob;
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
 *
 * ## Reader jobs (plan 3.5)
 *
 * The same class writes `rfq_reader_jobs` and a finished read's rows
 * ({@see RfqReaderJobStore}), because a read that replaces a draft's rows must
 * move the rows, the revision, the activity and the job in one transaction.
 * A claim returns only the rows its own `UPDATE` claimed (`OUTPUT`), which
 * closes the source's gap where a second worker picked up the first one's
 * running jobs. The platform claims only jobs whose `spool_path` names a
 * stored document (`rfq-document:<id>`): a job the source queued is never its.
 *
 * ## Workflow writes (parity plan E6)
 *
 * The same class implements {@see RfqWorkflowStore}: claim, assign, workflow,
 * archive, restore and comment. Each locks the draft row like a content save,
 * checks the revision, writes one activity row, and leaves the revision and
 * `updated_at` alone (they describe saved content).
 *
 * ## The sync ledger (parity plan E7.3)
 *
 * And {@see RfqSyncStore}: one `rfq_sync_attempts` row per attempt to put a
 * revision into 4js, written before 4js is contacted and checkpointed as each
 * step settles. `begin` and `finish` lock the draft row, so a sync, a save and
 * an archive cannot interleave, and settle the draft in the same transaction.
 * The checkpoints in between are one small transaction each, as the source's
 * are: a crash between two of them leaves the row saying how far it got.
 */
final class AzureSqlRfqDraftStore implements RfqDraftStore, RfqReaderJobStore, RfqWorkflowStore, RfqSyncStore
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

    // ------------------------------------------------------------------ workflow (E6)

    public function claimForActor(string $draftId, int $expectedRevision, DraftActor $actor, ?string $actorName): bool
    {
        return $this->workflowWrite($draftId, $expectedRevision, function (array $current) use ($draftId, $actor, $actorName): void {
            if (DraftChangeLog::truthy($current['archived_at'])) {
                throw RfqDraftNotEditable::archived();
            }
            if (DraftChangeLog::truthy($current['assignee_email'])) {
                throw RfqDraftNotEditable::alreadyAssigned();
            }
            $this->connection->execute('UPDATE [rfq].[rfq_draft_quotes] SET [assignee_email] = :assignee WHERE [id] = :id', ['assignee' => $actor->email, 'id' => $draftId]);
            $this->addActivity($draftId, 'claimed', $actor, ['from' => null, 'to' => $actorName], self::revisionOf($current));
        });
    }

    public function assign(string $draftId, int $expectedRevision, ?string $assigneeEmail, ?string $fromName, ?string $toName, DraftActor $actor): bool
    {
        return $this->workflowWrite($draftId, $expectedRevision, function (array $current) use ($draftId, $assigneeEmail, $fromName, $toName, $actor): void {
            if (DraftChangeLog::truthy($current['archived_at'])) {
                throw RfqDraftNotEditable::archived();
            }
            $email = $assigneeEmail === null ? '' : StoreFormat::email($assigneeEmail);
            $this->connection->execute(
                'UPDATE [rfq].[rfq_draft_quotes] SET [assignee_email] = :assignee WHERE [id] = :id',
                ['assignee' => $email !== '' ? $email : null, 'id' => $draftId],
            );
            $this->addActivity($draftId, 'assigned', $actor, ['from' => $fromName, 'to' => $toName], self::revisionOf($current));
        });
    }

    public function setWorkflow(string $draftId, int $expectedRevision, Closure $decide, DraftActor $actor): bool
    {
        return $this->workflowWrite($draftId, $expectedRevision, function (array $current) use ($draftId, $decide, $actor): void {
            $change = $decide(
                StoreProjection::workflowStatus($current['status']),
                StoreProjection::priority($current['priority']),
                DraftChangeLog::truthy($current['archived_at']),
            );
            if ($change->storeStatus !== null && !in_array($change->storeStatus, StoreFormat::STATUSES, true)) {
                throw InvalidRfqValue::forOneOf('draft status', $change->storeStatus, StoreFormat::STATUSES);
            }
            if ($change->storeStatus !== null) {
                // A person moving the status clears the last sync's error, as a content save does.
                $this->connection->execute(
                    'UPDATE [rfq].[rfq_draft_quotes] SET [status] = :status, [priority] = :priority, [push_error] = NULL WHERE [id] = :id',
                    ['status' => $change->storeStatus, 'priority' => $change->priority->toString(), 'id' => $draftId],
                );
            } else {
                $this->connection->execute(
                    'UPDATE [rfq].[rfq_draft_quotes] SET [priority] = :priority WHERE [id] = :id',
                    ['priority' => $change->priority->toString(), 'id' => $draftId],
                );
            }
            $this->addActivity($draftId, 'workflow_updated', $actor, $change->detail, self::revisionOf($current));
        });
    }

    public function archive(string $draftId, int $expectedRevision, string $reason, DraftActor $actor): bool
    {
        return $this->workflowWrite($draftId, $expectedRevision, function (array $current) use ($draftId, $reason, $actor): void {
            if (DraftChangeLog::truthy($current['archived_at'])) {
                throw RfqDraftNotEditable::archived();
            }
            if (DraftChangeLog::truthy($current['sync_started_at'])) {
                throw RfqDraftNotEditable::syncRunning();
            }
            $this->connection->execute(
                'UPDATE [rfq].[rfq_draft_quotes]
                 SET [archived_at] = :at, [archived_by] = :by, [archive_reason] = :reason WHERE [id] = :id',
                ['at' => $this->nowIso(), 'by' => $actor->email, 'reason' => $reason, 'id' => $draftId],
            );
            $this->addActivity($draftId, 'archived', $actor, ['reason' => $reason], self::revisionOf($current));
        });
    }

    public function restore(string $draftId, int $expectedRevision, DraftActor $actor): bool
    {
        return $this->workflowWrite($draftId, $expectedRevision, function (array $current) use ($draftId, $actor): void {
            if (!DraftChangeLog::truthy($current['archived_at'])) {
                throw RfqDraftNotEditable::notArchived();
            }
            $this->connection->execute(
                'UPDATE [rfq].[rfq_draft_quotes] SET [archived_at] = NULL, [archived_by] = NULL, [archive_reason] = NULL WHERE [id] = :id',
                ['id' => $draftId],
            );
            $this->addActivity($draftId, 'restored', $actor, null, self::revisionOf($current));
        });
    }

    public function comment(string $draftId, int $expectedRevision, string $message, DraftActor $actor): bool
    {
        return $this->workflowWrite($draftId, $expectedRevision, function (array $current) use ($draftId, $message, $actor): void {
            $this->addActivity($draftId, 'commented', $actor, null, self::revisionOf($current), $message);
        });
    }

    /**
     * One workflow write: lock the draft row, check the revision, run `$write`
     * with the locked row, commit. False when there is no such draft.
     *
     * @param callable(array<string, mixed>): void $write
     */
    private function workflowWrite(string $draftId, int $expectedRevision, callable $write): bool
    {
        if (!StoreFormat::isId($draftId)) {
            return false;
        }

        return $this->runInTransaction($this->connection, function () use ($draftId, $expectedRevision, $write): bool {
            $current = $this->connection->fetchAll(
                'SELECT [sync_started_at], [status], [priority], [revision], [archived_at], [assignee_email]
                 FROM [rfq].[rfq_draft_quotes] WITH (UPDLOCK, HOLDLOCK, ROWLOCK)
                 WHERE [id] = :id',
                ['id' => $draftId],
            )[0] ?? null;
            if ($current === null) {
                return false;
            }
            $revision = self::revisionOf($current);
            if ($expectedRevision !== $revision) {
                throw new RfqRevisionConflict($revision, $expectedRevision);
            }
            $write($current);

            return true;
        });
    }

    /** @param array<string, mixed> $row */
    private static function revisionOf(array $row): int
    {
        return AzureSqlValueMapper::intValue($row['revision'] ?? 1) ?: 1;
    }


    // ------------------------------------------------------------------ sync ledger (E7.3)

    /** Stored errors per attempt are trimmed, as the source trims them (`_STORED_PUSH_ERRORS`). */
    private const STORED_PUSH_ERRORS = 50;

    public function begin(string $draftId, int $expectedRevision, DraftActor $actor, array $rep, array $plan, string $payload, string $payloadSha256, string $correlationId, string $origin): ?string
    {
        if (!StoreFormat::isId($draftId)) {
            return null;
        }
        $ids = $this->ids;

        return $this->runInTransaction($this->connection, function () use ($draftId, $expectedRevision, $actor, $rep, $plan, $payload, $payloadSha256, $correlationId, $origin, $ids): ?string {
            $current = $this->connection->fetchAll(
                'SELECT [sync_started_at], [status], [revision], [archived_at], [quote_details], [refno], [refver]
                 FROM [rfq].[rfq_draft_quotes] WITH (UPDLOCK, HOLDLOCK, ROWLOCK)
                 WHERE [id] = :id',
                ['id' => $draftId],
            )[0] ?? null;
            if ($current === null) {
                return null;
            }
            $revision = self::revisionOf($current);
            if ($expectedRevision !== $revision) {
                throw new RfqRevisionConflict($revision, $expectedRevision);
            }
            if (DraftChangeLog::truthy($current['archived_at'])) {
                throw RfqDraftNotEditable::archived();
            }
            if (DraftChangeLog::truthy($current['sync_started_at'])) {
                throw RfqDraftNotEditable::syncBlocked('A sync for this RFQ is already running.');
            }
            if (self::normaliseStatus($current['status']) === 'synced') {
                throw RfqDraftNotEditable::alreadySynced(self::text($current['refno']), self::text($current['refver']));
            }
            $this->assertReadsSettled($draftId);
            $this->assertNoOpenAttempt($draftId);

            $now = $this->nowIso();
            $details = PythonJson::decodeObject(self::text($current['quote_details']));
            $rewrite = ($rep['quoteDetails']['author'] ?? null) !== ($details['author'] ?? null);
            $this->connection->execute(
                'UPDATE [rfq].[rfq_draft_quotes]
                 SET [sync_started_at] = :now, [sync_revision] = :revision, [assignee_email] = :assignee'
                . ($rewrite ? ', [quote_details] = :details' : '') . '
                 WHERE [id] = :id',
                ['now' => $now, 'revision' => $revision, 'assignee' => $rep['assignee'], 'id' => $draftId]
                + ($rewrite ? ['details' => PythonJson::encode($rep['quoteDetails'] === [] ? new \stdClass() : $rep['quoteDetails'])] : []),
            );

            $attemptId = $ids();
            $lines = is_array($plan['line_items'] ?? null) ? count($plan['line_items']) : 0;
            $this->connection->execute(
                'INSERT INTO [rfq].[rfq_sync_attempts]
                    ([id], [draft_id], [created_at], [updated_at], [state], [revision], [actor_email], [actor_role], [actor_branch], [origin],
                     [payload], [payload_sha256], [attempt_no], [header_state], [lines_total], [lines_sent], [lines_failed], [next_line], [progress])
                 VALUES (:id, :draft, :created, :updated, N\'running\', :revision, :email, :role, :branch, :origin,
                     :payload, :sha, 1, N\'pending\', :total, 0, 0, 0, :progress)',
                [
                    'id' => $attemptId,
                    'draft' => $draftId,
                    'created' => $now,
                    'updated' => $now,
                    'revision' => $revision,
                    'email' => $actor->email,
                    'role' => $actor->role,
                    'branch' => $actor->branch,
                    'origin' => $origin !== '' ? $origin : null,
                    'payload' => $payload,
                    'sha' => $payloadSha256,
                    'total' => $lines,
                    // The correlation id and the local idempotency key travel with the attempt (plan 5.4).
                    // 4js has no idempotency key of its own, so this is a local guard, not a delivery guarantee.
                    'progress' => PythonJson::encode([
                        'pushed_items' => [],
                        'errors' => [],
                        'correlation_id' => $correlationId,
                        'idempotency_key' => sprintf('%s:%d:%s', $draftId, $revision, $payloadSha256),
                    ]),
                ],
            );
            $detail = ['attempt_id' => $attemptId, 'payload_sha256' => $payloadSha256, 'correlation_id' => $correlationId];
            if ($rep['assigneeChange'] !== null) {
                $detail['assignee'] = $rep['assigneeChange'];
            }
            if ($rep['authorSource'] !== null) {
                $detail['author_source'] = $rep['authorSource'];
            }
            $this->addActivity($draftId, 'sync_queued', $actor, $detail, $revision, sprintf('Queued for 4js: %d line(s)', $lines));

            return $attemptId;
        });
    }

    /**
     * The source's `acquire_sync_lock` checks on the draft's document reads,
     * with its wording: a read in flight, a failed one, or a proposal nobody
     * has accepted or dismissed all mean the rows to be quoted are not the
     * rows this RFQ has.
     */
    private function assertReadsSettled(string $draftId): void
    {
        $pending = $this->connection->fetchAll(
            "SELECT TOP 1 [id] FROM [rfq].[rfq_reader_jobs] WHERE [draft_id] = :id AND [status] IN (N'queued', N'running')",
            ['id' => $draftId],
        );
        if ($pending !== []) {
            throw RfqDraftNotEditable::syncBlocked('A document is still being read. Wait for the resulting lines before syncing.');
        }
        $open = $this->connection->fetchAll(
            "SELECT TOP 1 [file_name], [status] FROM [rfq].[rfq_reader_jobs]
             WHERE [draft_id] = :id AND ([status] = N'failed'
               OR (NULLIF(LTRIM(RTRIM([proposal])), N'') IS NOT NULL AND [proposal] NOT IN (N'{}', N'null') AND [status] NOT IN (N'superseded', N'dismissed')))",
            ['id' => $draftId],
        )[0] ?? null;
        if ($open !== null) {
            $name = trim((string) self::text($open['file_name'])) !== '' ? (string) self::text($open['file_name']) : 'a document';
            throw RfqDraftNotEditable::syncBlocked(
                strtolower((string) self::text($open['status'])) === 'failed'
                    ? $name . ' could not be read, so its lines are not on this quote. Add them by hand or dismiss the failed read before syncing.'
                    : 'A re-read of ' . $name . ' is offering replacement lines. Accept or dismiss it before syncing.',
            );
        }
    }

    /**
     * `SyncAttemptTransitionPolicy::mayEnqueue`: an open or uncertain attempt is
     * the lock. An uncertain one is named, because only a person can resolve it.
     */
    private function assertNoOpenAttempt(string $draftId): void
    {
        $rows = $this->connection->fetchAll('SELECT * FROM [rfq].[rfq_sync_attempts] WHERE [draft_id] = :id ORDER BY [created_at], [id]', ['id' => $draftId]);
        $states = array_map(static fn (array $r): SyncAttemptState => SyncAttemptState::fromStorage($r['state']), $rows);
        if (SyncAttemptTransitionPolicy::mayEnqueue($states)) {
            return;
        }
        foreach ($rows as $row) {
            if (SyncAttemptState::fromStorage($row['state'])->is(SyncAttemptState::UNCERTAIN)) {
                throw new RfqSyncNeedsReconciliation(StoreProjection::syncAttemptView($row));
            }
        }
        throw RfqDraftNotEditable::syncBlocked('A sync for this RFQ is already running.');
    }

    public function noteHeaderIntent(string $attemptId): void
    {
        $now = $this->nowIso();
        $this->connection->execute(
            "UPDATE [rfq].[rfq_sync_attempts] SET [header_state] = N'sent', [header_sent_at] = :now, [updated_at] = :now2 WHERE [id] = :id AND [state] = N'running'",
            ['now' => $now, 'now2' => $now, 'id' => $attemptId],
        );
    }

    public function noteHeaderOk(string $attemptId, string $refno, string $refver): void
    {
        $this->runInTransaction($this->connection, function () use ($attemptId, $refno, $refver): void {
            $now = $this->nowIso();
            $this->connection->execute(
                "UPDATE [rfq].[rfq_sync_attempts] SET [header_state] = N'confirmed', [refno] = :refno, [refver] = :refver, [updated_at] = :now WHERE [id] = :id AND [state] = N'running'",
                ['refno' => $refno, 'refver' => $refver, 'now' => $now, 'id' => $attemptId],
            );
            $row = $this->connection->fetchAll('SELECT [draft_id] FROM [rfq].[rfq_sync_attempts] WHERE [id] = :id', ['id' => $attemptId])[0] ?? null;
            if ($row !== null) {
                // Onto the draft too, at once: the quote number exists in 4js from this moment, whatever happens next.
                $this->connection->execute(
                    "UPDATE [rfq].[rfq_draft_quotes] SET [refno] = :refno, [refver] = :refver WHERE [id] = :draft AND ([refno] IS NULL OR [refno] = N'')",
                    ['refno' => $refno, 'refver' => $refver, 'draft' => self::text($row['draft_id'])],
                );
            }
        });
    }

    public function noteHeaderFailed(string $attemptId, string $error, bool $certain): void
    {
        $this->connection->execute(
            "UPDATE [rfq].[rfq_sync_attempts] SET [header_state] = :state, [error] = :error, [updated_at] = :now WHERE [id] = :id AND [state] = N'running'",
            [
                'state' => $certain ? SyncHeaderState::REJECTED : SyncHeaderState::UNKNOWN,
                'error' => mb_substr($error, 0, 2_000),
                'now' => $this->nowIso(),
                'id' => $attemptId,
            ],
        );
    }

    public function noteLine(string $attemptId, int $index, bool $accepted, array $detail, int $nextLine): void
    {
        $this->runInTransaction($this->connection, function () use ($attemptId, $index, $accepted, $detail, $nextLine): void {
            $row = $this->connection->fetchAll(
                'SELECT [progress] FROM [rfq].[rfq_sync_attempts] WITH (UPDLOCK, ROWLOCK) WHERE [id] = :id',
                ['id' => $attemptId],
            )[0] ?? null;
            if ($row === null) {
                return;
            }
            $progress = PythonJson::decodeObject(self::text($row['progress']));
            $sent = is_array($progress['pushed_items'] ?? null) ? $progress['pushed_items'] : [];
            $errors = is_array($progress['errors'] ?? null) ? $progress['errors'] : [];
            if ($accepted) {
                $sent[] = $detail;
            } else {
                $errors[] = $detail + ['row_index' => $index];
            }
            $progress['pushed_items'] = $sent;
            $progress['errors'] = $errors;
            $this->connection->execute(
                "UPDATE [rfq].[rfq_sync_attempts]
                 SET [progress] = :progress, [lines_sent] = :sent, [lines_failed] = :failed, [next_line] = :next, [updated_at] = :now
                 WHERE [id] = :id AND [state] = N'running'",
                ['progress' => PythonJson::encode($progress), 'sent' => count($sent), 'failed' => count($errors), 'next' => $nextLine, 'now' => $this->nowIso(), 'id' => $attemptId],
            );
        });
    }

    public function finishAttempt(string $attemptId, SyncAttemptState $state, array $result, string $error, string $reconcileNote, DraftActor $actor): void
    {
        $this->runInTransaction($this->connection, function () use ($attemptId, $state, $result, $error, $reconcileNote, $actor): void {
            $attempt = $this->connection->fetchAll(
                'SELECT * FROM [rfq].[rfq_sync_attempts] WITH (UPDLOCK, ROWLOCK) WHERE [id] = :id',
                ['id' => $attemptId],
            )[0] ?? null;
            if ($attempt === null) {
                throw new \LogicException('No such sync attempt.');
            }
            $draftId = (string) self::text($attempt['draft_id']);
            $from = SyncAttemptState::fromStorage($attempt['state']);
            $header = SyncHeaderState::fromStorage($attempt['header_state']);
            $refno = trim((string) self::text($attempt['refno']));
            SyncAttemptTransitionPolicy::assertSystemMay($from, $state);
            SyncAttemptTransitionPolicy::assertMayFinish($state, $header, $refno !== '');

            $this->closeAttempt($attemptId, $state, $result, $error, $reconcileNote);
            $this->settleDraft($draftId, $attempt, $state, $result, $error, $actor, $refno, trim((string) self::text($attempt['refver'])));
        });
    }

    /** @param array<string, mixed> $result */
    private function closeAttempt(string $attemptId, SyncAttemptState $state, array $result, string $error, string $reconcileNote): void
    {
        $this->connection->execute(
            'UPDATE [rfq].[rfq_sync_attempts] SET [state] = :state, [result] = :result, [error] = :error, [reconcile_note] = :note, [updated_at] = :now WHERE [id] = :id',
            [
                'state' => $state->toString(),
                'result' => PythonJson::encode($result === [] ? new \stdClass() : $result),
                'error' => $error !== '' ? mb_substr($error, 0, 2_000) : null,
                'note' => $reconcileNote !== '' ? $reconcileNote : null,
                'now' => $this->nowIso(),
                'id' => $attemptId,
            ],
        );
    }

    /**
     * What the finished attempt does to the draft, in the attempt's transaction:
     * `synced` with its quote number on success (the system's `completed`), else
     * `failed` with the reason and any quote number kept, so a retry versions
     * that quote. Releases the sync lock and advances the revision, as the
     * source's `mark_synced` and `mark_push_failed` do.
     *
     * @param array<string, mixed> $attempt the attempt row
     * @param array<string, mixed> $result
     */
    private function settleDraft(string $draftId, array $attempt, SyncAttemptState $state, array $result, string $error, ?DraftActor $actor, string $refno, string $refver): void
    {
        $draft = $this->connection->fetchAll(
            'SELECT [revision], [refno], [refver] FROM [rfq].[rfq_draft_quotes] WITH (UPDLOCK, HOLDLOCK, ROWLOCK) WHERE [id] = :id',
            ['id' => $draftId],
        )[0] ?? null;
        if ($draft === null) {
            return;
        }
        $next = self::revisionOf($draft) + 1;
        $now = $this->nowIso();
        $syncedRevision = AzureSqlValueMapper::intValue($attempt['revision'] ?? 1) ?: 1;
        $nextRefno = $refno !== '' ? $refno : (string) self::text($draft['refno']);
        $nextRefver = $refver !== '' ? $refver : (string) self::text($draft['refver']);

        if ($state->is(SyncAttemptState::SUCCEEDED)) {
            $this->connection->execute(
                "UPDATE [rfq].[rfq_draft_quotes]
                 SET [status] = N'synced', [push_error] = NULL, [refno] = :refno, [refver] = :refver, [synced_at] = :now, [sync_payload] = :payload,
                     [sync_started_at] = NULL, [sync_revision] = NULL, [updated_at] = :now2, [revision] = :revision
                 WHERE [id] = :id",
                [
                    'refno' => $nextRefno,
                    'refver' => $nextRefver,
                    'now' => $now,
                    'payload' => PythonJson::encode($result + ['synced_revision' => $syncedRevision]),
                    'now2' => $now,
                    'revision' => $next,
                    'id' => $draftId,
                ],
            );
        } else {
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
            $outcome = [
                'ok' => false,
                'pushed' => AzureSqlValueMapper::intValue($result['pushed'] ?? 0),
                'failed' => count($errors),
                'total' => AzureSqlValueMapper::intValue($result['pushed'] ?? 0) + count($errors),
                'refno' => $nextRefno,
                'refver' => $nextRefver,
                'errors' => array_map(
                    static fn (mixed $e): array => ['code' => PythonText::str(is_array($e) ? ($e['code'] ?? '') : ''), 'error' => PythonText::str(is_array($e) ? ($e['error'] ?? '') : '')],
                    array_slice($errors, 0, self::STORED_PUSH_ERRORS),
                ),
                'errors_truncated' => max(0, count($errors) - self::STORED_PUSH_ERRORS),
                'recorded_at' => $now,
            ];
            $this->connection->execute(
                "UPDATE [rfq].[rfq_draft_quotes]
                 SET [status] = N'failed', [push_error] = :error, [refno] = :refno, [refver] = :refver, [sync_payload] = :payload,
                     [sync_started_at] = NULL, [sync_revision] = NULL, [updated_at] = :now, [revision] = :revision
                 WHERE [id] = :id",
                [
                    'error' => mb_substr($error, 0, 2_000),
                    'refno' => $nextRefno !== '' ? $nextRefno : null,
                    'refver' => $nextRefver !== '' ? $nextRefver : null,
                    'payload' => PythonJson::encode($outcome),
                    'now' => $now,
                    'revision' => $next,
                    'id' => $draftId,
                ],
            );
        }
        $detail = [
            'attempt_id' => (string) self::text($attempt['id']),
            'state' => $state->toString(),
            'lines_sent' => AzureSqlValueMapper::intValue($result['pushed'] ?? 0),
            'lines_failed' => is_array($result['errors'] ?? null) ? count($result['errors']) : 0,
        ];
        if ($nextRefno !== '') {
            $detail['refno'] = $nextRefno;
            $detail['refver'] = $nextRefver;
        }
        $this->addActivity(
            $draftId,
            'sync_finished',
            $actor,
            $detail,
            $next,
            $state->is(SyncAttemptState::SUCCEEDED) ? sprintf('Quote %s v%s synced to 4js', $nextRefno, $nextRefver) : $error,
        );
    }

    public function attempt(string $attemptId): ?SyncAttemptView
    {
        $row = $this->connection->fetchAll('SELECT * FROM [rfq].[rfq_sync_attempts] WHERE [id] = :id', ['id' => $attemptId])[0] ?? null;

        return $row === null ? null : StoreProjection::syncAttemptView($row);
    }

    public function attemptsFor(string $draftId, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, min(100, $limit)) . ') * FROM [rfq].[rfq_sync_attempts] WHERE [draft_id] = :id ORDER BY [created_at] DESC, [attempt_no] DESC, [id]',
            ['id' => $draftId],
        );

        return array_map(StoreProjection::syncAttemptView(...), $rows);
    }

    public function sweepStale(string $draftId, string $beforeIso): int
    {
        if (!StoreFormat::isId($draftId)) {
            return 0;
        }

        return $this->runInTransaction($this->connection, function () use ($draftId, $beforeIso): int {
            // The draft first, so a sweep cannot interleave with a sync starting or finishing.
            $draft = $this->connection->fetchAll('SELECT [revision] FROM [rfq].[rfq_draft_quotes] WITH (UPDLOCK, HOLDLOCK, ROWLOCK) WHERE [id] = :id', ['id' => $draftId])[0] ?? null;
            if ($draft === null) {
                return 0;
            }
            $stale = $this->connection->fetchAll(
                "SELECT * FROM [rfq].[rfq_sync_attempts] WHERE [draft_id] = :id AND [state] = N'running' AND [updated_at] < :before ORDER BY [created_at], [id]",
                ['id' => $draftId, 'before' => $beforeIso],
            );
            foreach ($stale as $attempt) {
                $header = SyncHeaderState::fromStorage($attempt['header_state']);
                $refno = trim((string) self::text($attempt['refno']));
                $outcome = SyncAttemptTransitionPolicy::sweepOutcome($header, $refno !== '', AzureSqlValueMapper::intValue($attempt['attempt_no']));
                $sent = AzureSqlValueMapper::intValue($attempt['lines_sent']);
                if ($outcome->is(SyncAttemptState::UNCERTAIN)) {
                    $state = SyncAttemptState::fromString(SyncAttemptState::UNCERTAIN);
                    $error = 'The sync was interrupted while the quote header was being sent, so 4js may hold a quote nobody has the number of.';
                    $note = 'The quote header request may not have completed. Do not sync again until 4js has been checked for a DRAFT quote for this customer raised around '
                        . ((string) self::text($attempt['header_sent_at']) ?: 'the attempt time') . '.';
                } else {
                    // Nothing resumes a half-sent attempt in this slice, so it is closed, with the reason. If the header landed
                    // its number is on the draft, and the next sync raises a new version of that quote, never a second quote.
                    $state = SyncAttemptState::fromString(SyncAttemptState::FAILED);
                    $error = $refno !== ''
                        ? sprintf('The sync was interrupted after quote %s was created (%d of %d line(s) sent). Syncing again raises a new version of that quote.', $refno, $sent, AzureSqlValueMapper::intValue($attempt['lines_total']))
                        : 'The sync was interrupted before anything was sent to 4js. Syncing again starts a new attempt.';
                    $note = '';
                }
                $progress = PythonJson::decodeObject(self::text($attempt['progress']));
                $errors = is_array($progress['errors'] ?? null) ? $progress['errors'] : [];
                $this->closeAttempt((string) self::text($attempt['id']), $state, ['ok' => false, 'pushed' => $sent, 'errors' => $errors, 'swept' => true], $error, $note);
                $this->settleDraft($draftId, $attempt, $state, ['pushed' => $sent, 'errors' => $errors], $error, null, $refno, trim((string) self::text($attempt['refver'])));
            }

            return count($stale);
        });
    }

    // ------------------------------------------------------------------ reader jobs

    public function enqueue(string $draftId, string $documentId, string $fileName, ?string $contentType, string $uploaderEmail, int $baseRevision): string
    {
        $ids = $this->ids;
        $jobId = $ids();
        $now = $this->nowIso();
        $this->connection->execute(
            'INSERT INTO [rfq].[rfq_reader_jobs]
                ([id], [created_at], [updated_at], [draft_id], [uploader_email], [file_name], [content_type], [spool_path], [status], [attempts], [base_revision])
             VALUES (:id, :created_at, :updated_at, :draft_id, :uploader_email, :file_name, :content_type, :spool_path, N\'queued\', 0, :base_revision)',
            [
                'id' => $jobId,
                'created_at' => $now,
                'updated_at' => $now,
                'draft_id' => $draftId,
                'uploader_email' => $uploaderEmail !== '' ? $uploaderEmail : null,
                'file_name' => $fileName,
                'content_type' => $contentType !== null && $contentType !== '' ? $contentType : null,
                'spool_path' => StoredReaderJob::spoolPath($documentId),
                'base_revision' => $baseRevision,
            ],
        );

        return $jobId;
    }

    public function requeueStale(string $beforeIso, int $maxAttempts): int
    {
        return $this->runInTransaction($this->connection, function () use ($beforeIso, $maxAttempts): int {
            $now = $this->nowIso();
            $failed = $this->connection->fetchAll(
                "UPDATE [rfq].[rfq_reader_jobs]
                 SET [status] = N'failed', [updated_at] = :now, [note] = N'The reader was interrupted repeatedly and gave up.'
                 OUTPUT inserted.[id]
                 WHERE [status] = N'running' AND [updated_at] < :before AND [attempts] >= :max AND [spool_path] LIKE N'rfq-document:%'",
                ['now' => $now, 'before' => $beforeIso, 'max' => $maxAttempts],
            );
            $requeued = $this->connection->fetchAll(
                "UPDATE [rfq].[rfq_reader_jobs] SET [status] = N'queued', [updated_at] = :now
                 OUTPUT inserted.[id]
                 WHERE [status] = N'running' AND [updated_at] < :before AND [spool_path] LIKE N'rfq-document:%'",
                ['now' => $now, 'before' => $beforeIso],
            );

            return count($failed) + count($requeued);
        });
    }

    public function nextDraft(): ?string
    {
        $row = $this->connection->fetchAll(
            "SELECT TOP 1 [draft_id] FROM [rfq].[rfq_reader_jobs] WITH (READPAST)
             WHERE [status] = N'queued' AND [draft_id] IS NOT NULL AND [spool_path] LIKE N'rfq-document:%'
             ORDER BY [created_at], [id]",
        )[0] ?? null;

        return $row === null ? null : self::text($row['draft_id']);
    }

    public function outstanding(): int
    {
        $row = $this->connection->fetchAll(
            "SELECT COUNT(*) AS [outstanding] FROM [rfq].[rfq_reader_jobs]
             WHERE [status] IN (N'queued', N'running') AND [spool_path] LIKE N'rfq-document:%'",
        )[0] ?? null;

        return $row === null ? 0 : AzureSqlValueMapper::intValue($row['outstanding']);
    }

    public function claim(string $draftId): array
    {
        $rows = $this->connection->fetchAll(
            "UPDATE [rfq].[rfq_reader_jobs]
             SET [status] = N'running', [attempts] = [attempts] + 1, [updated_at] = :now
             OUTPUT inserted.*
             WHERE [draft_id] = :draft AND [status] = N'queued' AND [spool_path] LIKE N'rfq-document:%'",
            ['now' => $this->nowIso(), 'draft' => $draftId],
        );
        $jobs = array_map(self::job(...), $rows);
        usort($jobs, static fn (StoredReaderJob $a, StoredReaderJob $b): int => [$a->updatedAt, $a->id] <=> [$b->updatedAt, $b->id]);

        return $jobs;
    }

    public function finish(string $jobId, string $status, ?string $note, ?array $proposal = null): void
    {
        if (!in_array($status, ['done', 'failed', 'superseded', 'queued'], true)) {
            throw InvalidRfqValue::forOneOf('reader job status', $status, ['done', 'failed', 'superseded', 'queued']);
        }
        $this->connection->execute(
            'UPDATE [rfq].[rfq_reader_jobs] SET [status] = :status, [updated_at] = :now, [note] = :note, [proposal] = :proposal WHERE [id] = :id',
            [
                'status' => $status,
                'now' => $this->nowIso(),
                'note' => $note !== null && $note !== '' ? $note : null,
                'proposal' => $proposal !== null ? PythonJson::encode($proposal) : null,
                'id' => $jobId,
            ],
        );
    }

    public function forDraft(string $draftId): array
    {
        return array_map(self::job(...), $this->connection->fetchAll(
            'SELECT * FROM [rfq].[rfq_reader_jobs] WHERE [draft_id] = :draft ORDER BY [created_at], [id]',
            ['draft' => $draftId],
        ));
    }

    public function find(string $draftId, string $jobId): ?StoredReaderJob
    {
        $row = $this->connection->fetchAll(
            'SELECT * FROM [rfq].[rfq_reader_jobs] WHERE [id] = :id AND [draft_id] = :draft',
            ['id' => $jobId, 'draft' => $draftId],
        )[0] ?? null;

        return $row === null ? null : self::job($row);
    }

    public function isUntouched(string $draftId, ?int $baseRevision): bool
    {
        if ($baseRevision === null) {
            return false;
        }
        $row = $this->connection->fetchAll(
            'SELECT d.[revision], d.[archived_at],
                    (SELECT COUNT(*) FROM [rfq].[rfq_draft_row_edits] e WHERE e.[draft_id] = d.[id]) AS [edits]
             FROM [rfq].[rfq_draft_quotes] d WHERE d.[id] = :id',
            ['id' => $draftId],
        )[0] ?? null;
        if ($row === null || DraftChangeLog::truthy($row['archived_at'])) {
            return false;
        }

        return (AzureSqlValueMapper::intValue($row['revision']) ?: 1) === $baseRevision
            && AzureSqlValueMapper::intValue($row['edits']) === 0;
    }

    public function applyRows(string $draftId, array $rowsByFile, ?int $expectedRevision, string $reader, string $message, array $finish = [], ?DraftActor $actor = null, string $eventType = 'reader_rows_replaced'): ?int
    {
        return $this->runInTransaction($this->connection, function () use ($draftId, $rowsByFile, $expectedRevision, $reader, $message, $finish, $actor, $eventType): ?int {
            $current = $this->connection->fetchAll(
                'SELECT [sync_started_at], [revision], [archived_at], [email_meta]
                 FROM [rfq].[rfq_draft_quotes] WITH (UPDLOCK, HOLDLOCK, ROWLOCK) WHERE [id] = :id',
                ['id' => $draftId],
            )[0] ?? null;
            if ($current === null) {
                return null;
            }
            $revision = AzureSqlValueMapper::intValue($current['revision'] ?? 1) ?: 1;
            if (DraftChangeLog::truthy($current['archived_at'])) {
                throw RfqDraftNotEditable::archived();
            }
            if ($expectedRevision !== null && $expectedRevision !== $revision) {
                throw new RfqRevisionConflict($revision, $expectedRevision);
            }
            if (DraftChangeLog::truthy($current['sync_started_at'])) {
                throw RfqDraftNotEditable::syncRunning();
            }
            $existing = $this->connection->fetchAll(
                'SELECT [row_data], [system_row_data], [system_snapshot_valid], [line_uid]
                 FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = :id ORDER BY [row_order]',
                ['id' => $draftId],
            );

            // Each file's rows go where its first old row was, wholesale; the
            // rest keep their text, snapshot and uid exactly.
            $ordered = [];
            $spliced = [];
            foreach ($existing as $row) {
                $data = PythonJson::decodeObject(self::text($row['row_data']));
                $source = is_array($data['source'] ?? null) ? $data['source'] : [];
                $file = is_string($source['file_name'] ?? null) ? $source['file_name'] : '';
                if ($file !== '' && array_key_exists($file, $rowsByFile)) {
                    if (!isset($spliced[$file])) {
                        $spliced[$file] = true;
                        foreach ($rowsByFile[$file] as $new) {
                            $ordered[] = ['new' => $new];
                        }
                    }
                    continue;
                }
                $ordered[] = ['kept' => $row];
            }
            foreach ($rowsByFile as $file => $rows) {
                if (!isset($spliced[$file])) {
                    foreach ($rows as $new) {
                        $ordered[] = ['new' => $new];
                    }
                }
            }

            $ids = $this->ids;
            $values = [];
            foreach ($ordered as $order => $entry) {
                if (isset($entry['kept'])) {
                    $kept = $entry['kept'];
                    $uid = self::text($kept['line_uid']) ?? '';
                    $values[] = [
                        'id' => $ids(),
                        'draft_id' => $draftId,
                        'row_order' => $order,
                        'row_data' => (string) self::text($kept['row_data']),
                        'system_row_data' => self::text($kept['system_row_data']),
                        'system_snapshot_valid' => AzureSqlValueMapper::intValue($kept['system_snapshot_valid']) !== 0 ? 1 : 0,
                        'line_uid' => $uid !== '' ? $uid : $ids(),
                    ];
                    continue;
                }
                $row = $entry['new'];
                unset($row['_draft_row_id'], $row['_system_snapshot_valid']);
                $uid = is_string($row['line_uid'] ?? null) && $row['line_uid'] !== '' ? $row['line_uid'] : $ids();
                $row['line_uid'] = $uid;
                $json = PythonJson::encode($row);
                // The re-read is the machine's new answer, so it is the snapshot
                // a rep's later edits are measured against (source `initial`).
                $values[] = [
                    'id' => $ids(),
                    'draft_id' => $draftId,
                    'row_order' => $order,
                    'row_data' => $json,
                    'system_row_data' => $json,
                    'system_snapshot_valid' => 1,
                    'line_uid' => $uid,
                ];
            }

            $next = $revision + 1;
            $this->connection->execute('DELETE FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = :id', ['id' => $draftId]);
            $this->insertRows($values);

            $meta = PythonJson::decodeObject(self::text($current['email_meta']));
            $parse = is_array($meta['parse'] ?? null) ? $meta['parse'] : [];
            $coverage = is_array($parse['coverage'] ?? null) ? $parse['coverage'] : [];
            $coverage['measured'] = false;
            $parse['coverage'] = $coverage;
            $parse['read_by'] = $reader;
            $meta['parse'] = $parse;
            $this->connection->execute(
                'UPDATE [rfq].[rfq_draft_quotes] SET [updated_at] = :now, [revision] = :revision, [email_meta] = :meta WHERE [id] = :id',
                ['now' => $this->nowIso(), 'revision' => $next, 'meta' => PythonJson::encode($meta), 'id' => $draftId],
            );
            $files = array_keys($rowsByFile);
            sort($files);
            $this->addActivity($draftId, $eventType, $actor, [
                'reader' => $reader,
                'files' => $files,
                'rows_before' => count($existing),
                'rows_after' => count($values),
            ], $next, $message);
            foreach ($finish as $jobId => $note) {
                $this->finish((string) $jobId, 'done', $note);
            }

            return $next;
        });
    }

    public function close(string $draftId, string $jobId, bool $accepted, bool $wasFailure, string $fileName, DraftActor $actor): void
    {
        $verb = $accepted ? 'accepted' : 'dismissed';
        $this->runInTransaction($this->connection, function () use ($draftId, $jobId, $accepted, $wasFailure, $fileName, $actor, $verb): void {
            $this->connection->execute(
                'UPDATE [rfq].[rfq_reader_jobs] SET [status] = :status, [proposal] = NULL, [updated_at] = :now, [note] = :note WHERE [id] = :id AND [draft_id] = :draft',
                ['status' => $accepted ? 'done' : 'dismissed', 'now' => $this->nowIso(), 'note' => "Proposal {$verb} by {$actor->email}", 'id' => $jobId, 'draft' => $draftId],
            );
            $this->addActivity(
                $draftId,
                "reader_proposal_{$verb}",
                $actor,
                ['job_id' => $jobId, 'file_name' => $fileName],
                null,
                $wasFailure ? "{$fileName}: failed read acknowledged" : "{$fileName}: re-read {$verb}",
            );
        });
    }

    public function recordEvent(string $draftId, string $eventType, string $message, array $detail = []): void
    {
        $this->addActivity($draftId, $eventType, null, $detail, null, $message);
    }

    public function refreshStatus(string $draftId, bool $ready): void
    {
        $this->connection->execute(
            "UPDATE [rfq].[rfq_draft_quotes] SET [status] = :target, [updated_at] = :now
             WHERE [id] = :id AND ISNULL(NULLIF(LTRIM(RTRIM([status])), N''), N'new') IN (N'new', N'in_progress', N'ready_to_sync', N'pending')
               AND ISNULL(NULLIF(LTRIM(RTRIM([status])), N''), N'new') <> :current",
            ['target' => $ready ? 'ready_to_sync' : 'in_progress', 'current' => $ready ? 'ready_to_sync' : 'in_progress', 'now' => $this->nowIso(), 'id' => $draftId],
        );
    }

    /** @param array<string, mixed> $row */
    private static function job(array $row): StoredReaderJob
    {
        $proposal = self::text($row['proposal'] ?? null);
        $decoded = $proposal !== null && trim($proposal) !== '' && !in_array(trim($proposal), ['{}', 'null'], true) ? PythonJson::decodeObject($proposal) : null;
        $base = $row['base_revision'] ?? null;

        return new StoredReaderJob(
            id: (string) self::text($row['id']),
            draftId: (string) self::text($row['draft_id']),
            fileName: (string) self::text($row['file_name']),
            contentType: self::text($row['content_type'] ?? null),
            status: strtolower(trim((string) self::text($row['status']))),
            attempts: AzureSqlValueMapper::intValue($row['attempts']),
            baseRevision: $base === null ? null : AzureSqlValueMapper::intValue($base),
            note: self::text($row['note'] ?? null),
            proposal: $decoded === [] ? null : $decoded,
            documentId: StoredReaderJob::documentIdFrom(self::text($row['spool_path'] ?? null)),
            updatedAt: (string) self::text($row['updated_at'] ?? ''),
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
