<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\StoredReaderJob;

/**
 * Background reads of uploaded documents (`rfq_reader_jobs`), and the writes a
 * finished read makes to its draft. The source's `draft_store` reader methods,
 * ported (plan 3.5; architecture doc §6.4: reader jobs are PHP's, the parser
 * holds no queue state).
 *
 * The values stored are the source's, so migrated jobs and platform jobs read
 * the same: a finished read is `done`, with its rows in `proposal` when the rep
 * had edited the draft and nothing there when they replaced its rows.
 */
interface RfqReaderJobStore
{
    /**
     * Queue one document for a read. `baseRevision` is the draft's revision
     * when the upload was answered: the apply-or-propose test is against it.
     *
     * @return string the job id
     */
    public function enqueue(string $draftId, string $documentId, string $fileName, ?string $contentType, string $uploaderEmail, int $baseRevision): string;

    /**
     * Records one email follow-up as a finished read (RFQ mail M6.2): a `rfq_reader_jobs` row that never queues
     * (its `spool_path` is `mail-followup:<messageKey>`, which the worker's `rfq-document:` filter ignores) and
     * whose `proposal` holds the follow-up's parsed rows, so the editor's existing Accept / Dismiss applies.
     * With no `proposal` the row is a `superseded` marker (the email held no lines), so the message is still
     * recorded once. **Idempotent per `$jobId`**: a second call changes nothing and answers false.
     *
     * @param array<string, mixed>|null $proposal
     *
     * @return bool true when newly recorded
     */
    public function recordEmailProposal(string $draftId, string $jobId, string $fileName, string $messageKey, string $uploaderEmail, int $baseRevision, ?string $note, ?array $proposal): bool;

    /**
     * Abandoned `running` jobs claimed before `$beforeIso` go back to `queued`,
     * or to `failed` once they have had `$maxAttempts`.
     *
     * @return int how many changed
     */
    public function requeueStale(string $beforeIso, int $maxAttempts): int;

    /** The draft whose platform read has waited longest, or null. */
    public function nextDraft(): ?string;

    /**
     * Platform reads not yet finished: queued, or running (claimed by a worker,
     * or abandoned and waiting for {@see requeueStale()}).
     */
    public function outstanding(): int;

    /**
     * Claim every queued platform read of one draft: `running`, attempts + 1.
     * Only the rows this call claimed are returned, so two workers cannot both
     * hold one job.
     *
     * @return list<StoredReaderJob>
     */
    public function claim(string $draftId): array;

    /**
     * @param string                    $status `done`, `failed`, `superseded` or `queued` (a retry)
     * @param array<string, mixed>|null $proposal
     */
    public function finish(string $jobId, string $status, ?string $note, ?array $proposal = null): void;

    /** @return list<StoredReaderJob> every read of one draft, oldest first */
    public function forDraft(string $draftId): array;

    public function find(string $draftId, string $jobId): ?StoredReaderJob;

    /**
     * The source's `draft_is_untouched`: still at `$baseRevision`, not archived,
     * and no row edit ever recorded.
     */
    public function isUntouched(string $draftId, ?int $baseRevision): bool;

    /**
     * The source's `apply_reader_rows`: each file's rows replaced wholesale at
     * the position its first old row held, new files appended, every other row
     * kept as it is; revision + 1; one `reader_rows_replaced` activity. The
     * jobs in `$finish` are closed `done` in the same transaction, and the
     * draft's recorded coverage becomes unmeasured (a model's rows).
     *
     * @param array<string, list<array<string, mixed>>> $rowsByFile
     * @param array<string, string>                     $finish     job id => note
     *
     * @return int|null the new revision; null when the draft is gone
     *
     * @throws RfqRevisionConflict when `$expectedRevision` is set and not current
     * @throws RfqDraftNotEditable when it is archived or syncing
     */
    public function applyRows(string $draftId, array $rowsByFile, ?int $expectedRevision, string $reader, string $message, array $finish = [], ?DraftActor $actor = null, string $eventType = 'reader_rows_replaced'): ?int;

    /**
     * A person's decision on one read (`resolve_reader_job` after the rows are
     * spliced): `done` without a proposal when accepted, `dismissed` otherwise,
     * with one activity naming the file.
     */
    public function close(string $draftId, string $jobId, bool $accepted, bool $wasFailure, string $fileName, DraftActor $actor): void;

    /**
     * @param array<string, mixed> $detail
     */
    public function recordEvent(string $draftId, string $eventType, string $message, array $detail = []): void;

    /**
     * The source's `refresh_ready_status`: moves only between `new`,
     * `in_progress` and `ready_to_sync`, and never bumps the revision.
     */
    public function refreshStatus(string $draftId, bool $ready): void;
}
