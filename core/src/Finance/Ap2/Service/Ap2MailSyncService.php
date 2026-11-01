<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateInterval;
use DateTimeImmutable;
use Throwable;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2MailClassification;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceExtractor;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailClassifier;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailCutoffUnset;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailAttachment;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailExtraction;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Domain\Ap2SyncRun;
use TriCab\Core\Finance\Ap2\Domain\Ap2Upstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;

/**
 * `POST /mail/sync`: copies new messages of the AP mailbox's Inbox into `ap2_mail`. READ ONLY toward the mailbox: it
 * lists, reads and downloads attachments through the shared {@see FinanceMailboxReader} (application key AP2, which
 * audits every read) and never moves, flags or marks anything.
 *
 *  - Window: there is no period to choose. One cutoff date (setting `mailCutoffDate`, a Sydney day; until it is set a sync
 *    refuses with 409 FINANCE_AP2_MAIL_CUTOFF_UNSET) and only mail received on or after it is ever synced. A sync reads
 *    from max(start of the cutoff day, last successful run's high-water mark minus 1 hour) up to now, in successive
 *    windows of at most 31 days / 500 messages (a window that hits 500 is halved), moving the mark over each finished
 *    window so a long gap is caught up without skipping anything.
 *  - Dedupe: a message already stored for the mailbox (its Graph immutable id) is skipped.
 *  - Per new message: the body as plain text; attachments (not inline images) into `finance.attachments`; the
 *    supplier by sender address or domain ({@see Ap2SupplierMatcher}), then the classifier for the category, a
 *    summary and (only if the address matched nobody) a supplier; one history line when a supplier is known.
 *  - A 'Supplier invoice' with a supplier and a PDF attachment also gets the invoice extractor run on its first PDF; the
 *    result is stored on the mail (`extraction_json`) as a suggestion for `create-invoice`. An invoice is never created
 *    here, and an extraction that fails or finds nothing is simply not stored.
 *  - A message that fails (blob, classifier, reader) is counted as failed and recorded; the batch goes on, and because
 *    the mail row is committed only after every attachment is stored (and linked in the same transaction), a failed or
 *    killed message leaves no row and is retried by the next sync. A refused or failed LISTING fails the run.
 *  - Every run is recorded in `ap2_sync_runs`. `POST /mail/sync` is client-driven in modes (`start`, `process`,
 *    `next`, `complete`, `cancel`), each request bounded by {@see BATCH_SECONDS} / {@see BATCH_MAX}; {@see sync()} loops the same
 *    code in-process for the scheduled job. One Running run at a time; stale after {@see STALE_MINUTES}.
 */
final class Ap2MailSyncService
{
    public const KIND = 'mail';

    public const MAX_ATTACHMENTS = 20;

    public const MAX_ATTACHMENT_BYTES = 20971520;

    /** The one-hour overlap of a sync's start before the last high-water mark (minutes). */
    public const OVERLAP_MINUTES = 60;

    /** The widest window, in calendar days (the shared reader's rule). */
    public const MAX_DAYS = 31;

    /** The most messages a window lists (the shared reader's ceiling). */
    public const LIST_MAX = 500;

    /** A `process` call stops starting new messages after this many seconds of wall clock (the web request limit is 30). */
    public const BATCH_SECONDS = 15;

    /** ... or after this many messages, whichever comes first. */
    public const BATCH_MAX = 10;

    /** The most ids one `process` call may be sent. */
    public const MAX_IDS_PER_CALL = 50;

    /** The scheduled job's own budget (it has no browser waiting). */
    public const JOB_SECONDS = 240;

    /** A Running run with no progress for this long was left by a request that died: it no longer blocks or accepts work. */
    public const STALE_MINUTES = 10;

    public function __construct(
        private readonly Ap2MailStore $mail,
        private readonly Ap2SettingsStore $settings,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2HistoryStore $history,
        private readonly FinanceMailboxReader $reader,
        private readonly Ap2FileService $files,
        private readonly Ap2MailClassifier $classifier,
        private readonly Ap2InvoiceExtractor $extractor,
        private readonly Ap2Transactions $transactions,
        private readonly Ap2Time $time,
        private readonly string $mailbox,
        private readonly int $batchSeconds = self::BATCH_SECONDS,
        private readonly int $batchMax = self::BATCH_MAX,
        private readonly int $jobSeconds = self::JOB_SECONDS,
    ) {
    }

    /**
     * The client-driven sync behind `POST /mail/sync`: `{mode: start|resume|process|next|complete|cancel, ...}`. There is
     * no period to choose: the server decides the window (see {@see window()}).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function handle(?Ap2Actor $actor, array $body): array
    {
        $mode = $body['mode'] ?? null;
        if ($mode === 'start' && (array_key_exists('from', $body) || array_key_exists('to', $body))) {
            throw new Ap2Invalid('A sync takes no dates', Ap2Failure::details(['from and to are not accepted: the server syncs new mail since the cutoff date']));
        }

        return match ($mode) {
            'start' => $this->start($actor),
            'resume' => $this->resume($actor, $body),
            'process' => $this->processBatch($actor, $body),
            'next' => $this->next($actor, $body),
            'complete' => $this->close($body, Ap2SyncRun::SUCCEEDED),
            'cancel' => $this->close($body, Ap2SyncRun::CANCELLED),
            default => throw new Ap2Invalid('mode must be start, resume, process, next, complete or cancel', Ap2Failure::details(['mode must be start, resume, process, next, complete or cancel'])),
        };
    }

    /**
     * `start`: the first window's messages not yet stored, and a Running row for them.
     *
     * @return array<string, mixed>
     */
    private function start(?Ap2Actor $actor): array
    {
        $open = $this->open($actor?->userId);

        return ['runId' => $open['run']->id, 'status' => 'Running'] + $this->windowAnswer($open['run'], $open['pending'], $open['fetched']);
    }

    /**
     * What a window's opening tells the page: its ids, the run's running total and whether more windows follow.
     *
     * @param list<string> $pending
     * @return array<string, mixed>
     */
    private function windowAnswer(Ap2SyncRun $run, array $pending, int $listed): array
    {
        return [
            'from' => $run->windowStartAt?->format('c'),
            'to' => $run->windowEndAt?->format('c'),
            'fetched' => $listed,
            'total' => $run->fetched,
            'pending' => $pending,
            'more' => $this->hasMore($run),
            'done' => $pending === [] && !$this->hasMore($run),
        ];
    }

    private function hasMore(Ap2SyncRun $run): bool
    {
        return $run->windowEndAt !== null && $run->targetAt !== null && $run->windowEndAt < $run->targetAt;
    }

    /**
     * `resume`: the pending ids of a Running run again (the page was reloaded and lost its list): the run's current window
     * is listed afresh and what is already stored (by this run or any) is left out.
     *
     * @param array<string, mixed> $body `{runId}`
     * @return array<string, mixed>
     */
    private function resume(?Ap2Actor $actor, array $body): array
    {
        $run = $this->liveRun($body);
        if ($run->startedByUserId === null) {
            // The hourly job owns this run: report its counts only, no ids and no change to the run.
            return ['runId' => $run->id, 'status' => $run->status, 'startedBy' => 'job', 'pending' => [], 'more' => false, 'done' => false, 'total' => $run->fetched, 'run' => ['total' => $run->fetched, 'processed' => $run->processed, 'created' => $run->created, 'skipped' => $run->skipped, 'failed' => $run->failed]];
        }
        $now = $this->time->now();
        $start = $run->windowStartAt ?? $run->startedAt->sub(new DateInterval('P1D'));
        $end = $run->windowEndAt ?? $now;
        $listed = $this->listFailing(fn (): array => $this->reader->listMessages(Module::APP_AP2, $this->mailbox, $start, $end, self::LIST_MAX, $actor?->userId, 'inbox'));
        $pending = $this->unstored($listed);
        $run = $run->with(fetched: $run->processed + count($pending), updatedAt: $now);
        $this->mail->saveProgress($run);

        return ['runId' => $run->id, 'status' => $run->status] + $this->windowAnswer($run, $pending, count($listed)) + [
            'run' => ['total' => $run->fetched, 'processed' => $run->processed, 'created' => $run->created, 'skipped' => $run->skipped, 'failed' => $run->failed],
        ];
    }

    /**
     * `next`: the current window is finished (every message handled), so the high-water mark moves to its end, unless a
     * message failed, and the next window is listed. A window with messages still unhandled is refused.
     *
     * @param array<string, mixed> $body `{runId}`
     * @return array<string, mixed>
     */
    private function next(?Ap2Actor $actor, array $body): array
    {
        $run = $this->ownedRun($body);
        if ($run->processed < $run->fetched) {
            throw new Ap2Conflict('The current window still has messages to process', Ap2Failure::details(['The current window still has messages to process']) + ['runId' => $run->id]);
        }
        $run = $this->advance($run);
        if (!$this->hasMore($run)) {
            $this->mail->saveProgress($run);

            return ['runId' => $run->id, 'status' => $run->status] + $this->windowAnswer($run, [], 0);
        }
        [$run, $pending, $listed] = $this->openWindow($run, $actor?->userId);

        return ['runId' => $run->id, 'status' => $run->status] + $this->windowAnswer($run, $pending, $listed);
    }

    /**
     * `process`: one bounded batch of `ids` (the leading ones, in order) of a Running run.
     *
     * @param array<string, mixed> $body `{runId, ids}`
     * @return array<string, mixed>
     */
    private function processBatch(?Ap2Actor $actor, array $body): array
    {
        $run = $this->ownedRun($body);
        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || $ids === [] || count($ids) > self::MAX_IDS_PER_CALL) {
            throw new Ap2Invalid('ids must be a list of 1 to ' . self::MAX_IDS_PER_CALL . ' message ids', Ap2Failure::details(['ids must be a list of 1 to ' . self::MAX_IDS_PER_CALL . ' message ids']));
        }
        $list = [];
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '' || strlen($id) > 500) {
                throw new Ap2Invalid('ids must be message ids', Ap2Failure::details(['ids must be message ids']));
            }
            $list[] = $id;
        }

        $result = $this->runIds($run, $list, $this->batchMax, $this->batchSeconds, $actor?->userId);
        $run = $result['run'];
        $remaining = max(0, $run->fetched - $run->processed);

        return [
            'runId' => $run->id,
            'status' => $run->status,
            'processed' => $result['processed'],
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'consumed' => $result['consumed'],
            'errors' => $result['errors'],
            'remaining' => $remaining,
            'more' => $this->hasMore($run),
            'done' => ($remaining === 0 && !$this->hasMore($run)) || $run->status !== Ap2SyncRun::RUNNING,
            'cancelled' => $run->status === Ap2SyncRun::CANCELLED,
            'run' => ['total' => $run->fetched, 'processed' => $run->processed, 'created' => $run->created, 'skipped' => $run->skipped, 'failed' => $run->failed],
        ];
    }

    /**
     * `complete` / `cancel`: closes a run. Completing moves the high-water mark over the last window when all of it was
     * handled. Closing a run already closed the same way changes nothing.
     *
     * @param array<string, mixed> $body `{runId}`
     * @return array<string, mixed>
     */
    private function close(array $body, string $status): array
    {
        $run = $this->findRun($body);
        if ($run->isRunning()) {
            if ($status === Ap2SyncRun::SUCCEEDED && $run->processed >= $run->fetched) {
                $run = $this->advance($run);
            }
            $note = $run->failed > 0 ? $run->failed . ' message(s) failed: ' . ($run->error ?? '') : $run->error;
            $run = $run->with(status: $status, error: $note === null ? null : mb_substr($note, 0, 1000), finishedAt: $this->time->now(), updatedAt: $this->time->now());
            $this->mail->finishRun($run);
        } elseif ($run->status !== $status) {
            throw new Ap2Conflict('This sync is already ' . strtolower($run->status), Ap2Failure::details(['This sync is already ' . strtolower($run->status)]) + ['runId' => $run->id]);
        }

        return ['runId' => $run->id, 'status' => $run->status, 'total' => $run->fetched, 'processed' => $run->processed, 'created' => $run->created, 'skipped' => $run->skipped, 'failed' => $run->failed, 'highWaterAt' => $run->highWaterAt?->format('c')];
    }

    /**
     * The in-process sync of the scheduled job: the same windows and batches, looped within its own budget. What the
     * budget leaves is picked up by the next run (the mark only moves over windows that were finished, and dedupe).
     *
     * @return array{fetched: int, created: int, skipped: int, failed: int, windows: int, complete: bool}
     */
    public function sync(?Ap2Actor $actor): array
    {
        $userId = $actor?->userId;
        $began = self::seconds($this->time->now());
        $open = $this->open($userId);
        $run = $open['run'];
        $pending = $open['pending'];
        $skipped = $open['fetched'] - count($pending);
        $listedTotal = $open['fetched'];
        $created = $failed = 0;
        $windows = 1;
        $complete = false;
        try {
            while (true) {
                $left = (int) max(1, $this->jobSeconds - (self::seconds($this->time->now()) - $began));
                $result = $this->runIds($run, $pending, PHP_INT_MAX, $left, $userId);
                $run = $result['run'];
                $created += $result['created'];
                $skipped += $result['skipped'];
                $failed += $result['failed'];
                if (!$run->isRunning() || $result['consumed'] < count($pending)) {
                    break;
                }
                $run = $this->advance($run);
                if (!$this->hasMore($run)) {
                    $complete = true;
                    break;
                }
                if (self::seconds($this->time->now()) - $began >= $this->jobSeconds) {
                    break;
                }
                [$run, $pending, $listed] = $this->openWindow($run, $userId);
                $skipped += $listed - count($pending);
                $listedTotal += $listed;
                ++$windows;
            }
        } catch (Throwable $failure) {
            $current = $this->mail->findRun($run->id) ?? $run;
            $this->mail->finishRun($current->with(status: Ap2SyncRun::FAILED, error: mb_substr($failure->getMessage(), 0, 1000), finishedAt: $this->time->now(), updatedAt: $this->time->now()));

            throw $failure;
        }
        $notes = [];
        if ($failed > 0) {
            $notes[] = $failed . ' message(s) failed';
        }
        if (!$complete && $run->isRunning()) {
            $notes[] = 'stopped at its time budget; the next run carries on';
        }
        if ($run->isRunning()) {
            $this->mail->finishRun($run->with(status: Ap2SyncRun::SUCCEEDED, error: $notes === [] ? null : mb_substr(implode('; ', $notes), 0, 1000), finishedAt: $this->time->now(), updatedAt: $this->time->now()));
        }

        return ['fetched' => $listedTotal, 'created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'windows' => $windows, 'complete' => $complete];
    }

    /** The cutoff as its first UTC instant (the start of that Sydney day), or the refusal while it is not set. */
    private function cutoffStart(): DateTimeImmutable
    {
        $day = $this->settings->get()->mailCutoffDate;
        if ($day === null) {
            throw new Ap2MailCutoffUnset();
        }

        return $this->time->dayBounds($day, null)[0] ?? throw new Ap2MailCutoffUnset();
    }

    /**
     * Where a sync starts: the later of the cutoff's first instant and one hour before the high-water mark of the last
     * successful run (the overlap; dedupe makes it harmless). `$now` is where it ends.
     */
    private function windowStart(): DateTimeImmutable
    {
        $start = $this->cutoffStart();
        $mark = $this->mail->lastSucceededRun(self::KIND)?->highWaterAt;
        if ($mark !== null) {
            $start = max($start, $mark->sub(new DateInterval('PT' . self::OVERLAP_MINUTES . 'M')));
        }

        return $start;
    }

    /**
     * Refuses while no cutoff is set (409 FINANCE_AP2_MAIL_CUTOFF_UNSET) or another sync is running (409 with its id); a
     * running row with no progress for {@see STALE_MINUTES} (or none recorded) is marked Failed and does not block. Then
     * records the run and lists its first window.
     *
     * @return array{run: Ap2SyncRun, pending: list<string>, fetched: int}
     */
    private function open(?int $userId): array
    {
        $start = $this->windowStart();
        $now = $this->time->now();
        $running = $this->mail->runningRun(self::KIND);
        if ($running !== null) {
            if (!$running->isStale($now, self::STALE_MINUTES)) {
                throw new Ap2Conflict('A sync is already running', Ap2Failure::details(['A sync is already running']) + ['runId' => $running->id, 'run' => ['runId' => $running->id, 'status' => $running->status, 'startedBy' => $running->startedByUserId === null ? 'job' : 'user', 'total' => $running->fetched, 'processed' => $running->processed, 'created' => $running->created, 'skipped' => $running->skipped, 'failed' => $running->failed]]);
            }
            $this->mail->finishRun($running->with(status: Ap2SyncRun::FAILED, error: 'Abandoned: no progress for ' . self::STALE_MINUTES . ' minutes', finishedAt: $now));
        }

        // Everything before `$start` is already done (the cutoff, or the last run's mark), so the run's own mark starts there.
        $run = new Ap2SyncRun(
            bin2hex(random_bytes(16)),
            self::KIND,
            Ap2SyncRun::RUNNING,
            $now,
            $start->format('Y-m-d'),
            $now->format('Y-m-d'),
            startedByUserId: $userId,
            updatedAt: $now,
            highWaterAt: $start,
            windowStartAt: $start,
            windowEndAt: $start,
            targetAt: $now,
        );
        $this->mail->beginRun($run);
        try {
            [$run, $pending, $listed] = $this->openWindow($run, $userId);
        } catch (Throwable $failure) {
            $this->mail->finishRun($run->with(status: Ap2SyncRun::FAILED, error: mb_substr($failure->getMessage(), 0, 1000), finishedAt: $this->time->now(), updatedAt: $this->time->now()));

            throw $failure;
        }

        return ['run' => $run, 'pending' => $pending, 'fetched' => $listed];
    }

    /**
     * Lists the window that follows the run's last one: from where that ended to the target, at most 31 days wide, and
     * narrowed (halved, newest half dropped) while the reader's 500-message ceiling is hit, because it returns the
     * NEWEST messages and would otherwise hide the oldest ones. The run's total grows by the new window's pending ids.
     *
     * @return array{0: Ap2SyncRun, 1: list<string>, 2: int} the run, the pending ids, how many messages the window listed
     */
    private function openWindow(Ap2SyncRun $run, ?int $userId): array
    {
        $start = $run->windowEndAt ?? $run->windowStartAt ?? $this->time->now();
        $target = $run->targetAt ?? $this->time->now();
        $widest = $start->add(new DateInterval('P' . self::MAX_DAYS . 'D'))->sub(new DateInterval('PT1H'));
        $end = min($target, $widest);
        $listed = [];
        if ($start < $end) {
            for ($i = 0; $i < 40; ++$i) {
                $listed = $this->listFailing(fn (): array => $this->reader->listMessages(Module::APP_AP2, $this->mailbox, $start, $end, self::LIST_MAX, $userId, 'inbox'));
                $seconds = (int) $end->format('U') - (int) $start->format('U');
                if (count($listed) < self::LIST_MAX || $seconds <= 1) {
                    break;
                }
                $end = $start->add(new DateInterval('PT' . max(1, intdiv($seconds, 2)) . 'S'));
            }
        } else {
            $end = max($start, $target);
        }
        $pending = $this->unstored($listed);
        $run = $run->with(windowStartAt: $start, windowEndAt: $end, fetched: $run->fetched + count($pending), updatedAt: $this->time->now());
        $this->mail->saveProgress($run);

        return [$run, $pending, count($listed)];
    }

    /**
     * The current window is fully handled: the high-water mark moves to its end, but only while nothing has failed in the
     * run (a failed message must stay inside the next run's overlap, so the mark stops at the window before it).
     */
    private function advance(Ap2SyncRun $run): Ap2SyncRun
    {
        if ($run->failed === 0 && $run->windowEndAt !== null) {
            $run = $run->with(highWaterAt: max($run->highWaterAt ?? $run->windowEndAt, $run->windowEndAt));
            $this->mail->saveProgress($run);
        }

        return $run;
    }

    /**
     * @param list<MailboxMessageSummary> $listed
     * @return list<string>
     */
    private function unstored(array $listed): array
    {
        $pending = [];
        foreach ($listed as $summary) {
            if (!$this->mail->hasGraphMessage($this->mailbox, $summary->id)) {
                $pending[] = $summary->id;
            }
        }

        return $pending;
    }

    /**
     * @param callable(): list<MailboxMessageSummary> $list
     * @return list<MailboxMessageSummary>
     */
    private function listFailing(callable $list): array
    {
        try {
            return $list();
        } catch (Throwable $failure) {
            throw match (true) {
                $failure instanceof FinanceMailRefused => new Ap2Conflict('Tri-AP may not read ' . $this->mailbox, Ap2Failure::details([$failure->getMessage()]), $failure),
                $failure instanceof FinanceMailInvalid => new Ap2Invalid($failure->getMessage(), Ap2Failure::details([$failure->getMessage()]), $failure),
                $failure instanceof MailboxReadFailed => new Ap2Upstream('The mailbox could not be read', Ap2Failure::details([$failure->getMessage()]), $failure),
                default => $failure,
            };
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function findRun(array $body): Ap2SyncRun
    {
        $id = $body['runId'] ?? null;
        $run = is_string($id) && $id !== '' ? $this->mail->findRun($id) : null;
        if ($run === null || $run->kind !== self::KIND) {
            throw new Ap2NotFound('Sync run not found');
        }

        return $run;
    }

    /** A live run that a person may drive: the hourly job's run (no starting user) is refused (409). */
    /** @param array<string, mixed> $body */
    private function ownedRun(array $body): Ap2SyncRun
    {
        $run = $this->liveRun($body);
        if ($run->startedByUserId === null) {
            throw new Ap2Conflict('This sync belongs to the hourly job', Ap2Failure::details(['This sync belongs to the hourly job']) + ['runId' => $run->id, 'startedBy' => 'job']);
        }

        return $run;
    }

    /**
     * A run that may take more work: Running and not stale. A cancelled, finished or stale run refuses (409).
     *
     * @param array<string, mixed> $body
     */
    private function liveRun(array $body): Ap2SyncRun
    {
        $run = $this->findRun($body);
        $now = $this->time->now();
        if ($run->isRunning() && $run->isStale($now, self::STALE_MINUTES)) {
            $run = $run->with(status: Ap2SyncRun::FAILED, error: 'Abandoned: no progress for ' . self::STALE_MINUTES . ' minutes', finishedAt: $now);
            $this->mail->finishRun($run);
        }
        if (!$run->isRunning()) {
            $why = 'This sync is no longer running (' . strtolower($run->status) . ')';

            throw new Ap2Conflict($why, Ap2Failure::details([$why]) + ['runId' => $run->id, 'status' => $run->status]);
        }

        return $run;
    }

    /**
     * Handles `$ids` in order until `$max` have been handled or `$seconds` of wall clock have passed (no new message is
     * started after that), counting each in the run and stamping its progress. A message that fails is counted and
     * recorded and does not stop the batch; the run being cancelled meanwhile does.
     *
     * @param list<string> $ids
     * @return array{run: Ap2SyncRun, processed: int, created: int, skipped: int, failed: int, consumed: int, errors: list<string>}
     */
    private function runIds(Ap2SyncRun $run, array $ids, int $max, int $seconds, ?int $userId): array
    {
        $began = self::seconds($this->time->now());
        $suppliers = $this->suppliers->all();
        $created = $skipped = $failed = $consumed = 0;
        $errors = [];
        foreach ($ids as $id) {
            if ($consumed >= $max || ($consumed > 0 && self::seconds($this->time->now()) - $began >= $seconds)) {
                break;
            }
            $current = $this->mail->findRun($run->id);
            if ($current === null || !$current->isRunning()) {
                $run = $current ?? $run;
                break;
            }
            ++$consumed;
            $outcome = 'created';
            try {
                if ($this->mail->hasGraphMessage($this->mailbox, $id)) {
                    $outcome = 'skipped';
                } else {
                    $message = $this->reader->getMessage(Module::APP_AP2, $this->mailbox, $id, $userId);
                    $this->process($message->summary, $suppliers, $userId, $message);
                }
            } catch (FinanceMailRefused $refused) {
                throw new Ap2Conflict('Tri-AP may not read ' . $this->mailbox, Ap2Failure::details([$refused->getMessage()]), $refused);
            } catch (Throwable $failure) {
                $outcome = 'failed';
                $errors[] = mb_substr($failure::class . ': ' . $failure->getMessage(), 0, 300);
                error_log('Tri-AP mail sync: message failed: ' . $failure::class);
            }
            match ($outcome) {
                'created' => ++$created,
                'skipped' => ++$skipped,
                default => ++$failed,
            };
            $run = $current->with(
                processed: $current->processed + 1,
                created: $current->created + ($outcome === 'created' ? 1 : 0),
                skipped: $current->skipped + ($outcome === 'skipped' ? 1 : 0),
                failed: $current->failed + ($outcome === 'failed' ? 1 : 0),
                error: $outcome === 'failed' && $current->error === null ? $errors[array_key_last($errors)] : $current->error,
                updatedAt: $this->time->now(),
            );
            $this->mail->saveProgress($run);
        }

        return ['run' => $run, 'processed' => $consumed, 'created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'consumed' => $consumed, 'errors' => array_slice($errors, 0, 5)];
    }

    private static function seconds(DateTimeImmutable $at): float
    {
        return (float) $at->format('U.u');
    }

    /**
     * @param list<\TriCab\Core\Finance\Ap2\Domain\Ap2Supplier> $suppliers
     * @return bool true when a new row was stored, false when the message was already known
     */
    private function process(MailboxMessageSummary $summary, array $suppliers, ?int $userId, MailboxMessage $message): bool
    {
        $files = $this->readAttachments($summary, $userId);

        $text = self::text($message);
        $names = array_map(static fn (array $f): string => $f['name'], $files);
        $matched = Ap2SupplierMatcher::match($summary->fromAddress, $suppliers);
        $classification = $this->classify($summary, $text, $names, $suppliers);
        $known = array_map(static fn ($s): string => $s->id, $suppliers);
        $supplierId = $matched ?? ($classification->supplierId !== null && in_array($classification->supplierId, $known, true) ? $classification->supplierId : null);
        $category = $classification->category !== null && in_array($classification->category, Ap2Mail::CATEGORIES, true) ? $classification->category : 'Other';

        $now = $this->time->now();
        $mailId = bin2hex(random_bytes(16));
        $from = strtolower(trim($summary->fromAddress));
        $stored = [];
        $rows = [];
        $bytesOf = [];
        foreach ($files as $f) {
            $file = $this->files->store($f['name'], $f['type'], $f['bytes'], $userId);
            $stored[] = $file->id;
            $rows[] = new Ap2MailAttachment(bin2hex(random_bytes(16)), $mailId, $file->id, $f['name'], strlen($f['bytes']), $file->contentType, $now, $f['graphId']);
            $bytesOf[$rows[array_key_last($rows)]->id] = $f['bytes'];
        }
        $mail = new Ap2Mail(
            $mailId,
            $summary->receivedAt->setTimezone(new \DateTimeZone('UTC')),
            $now,
            $now,
            supplierId: $supplierId,
            mailbox: $this->mailbox,
            graphMessageId: $summary->id,
            subject: mb_substr(trim($summary->subject), 0, 400),
            bodyPreview: mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 300),
            body: $text,
            category: $category,
            from: $from,
            cc: self::copies($message),
            replyTo: $from,
            aiSummary: $classification->summary === null ? null : mb_substr($classification->summary, 0, 1000),
            aiConfidence: $classification->confidence > 0.0 ? max(0.0, min(1.0, $classification->confidence)) : null,
        );

        $mail = $mail->with(extraction: $this->extract($category, $supplierId, $suppliers, $rows, $bytesOf));

        $this->transactions->run(function () use ($mail, $rows, $now, $supplierId, $stored, $mailId, $userId): void {
            $this->mail->insertMail($mail, $rows);
            // Linked in the same transaction as the row: a mail is never committed with files that are not on it.
            foreach ($stored as $fileId) {
                $this->files->attachToMail($fileId, $mailId, $userId);
            }
            if ($supplierId !== null) {
                $this->history->append(new Ap2HistoryEntry(
                    bin2hex(random_bytes(16)),
                    $supplierId,
                    'Email received',
                    $mail->subject . ' · ' . $mail->from . ' · ' . $mail->category,
                    new Ap2Person(0, 'Tri-AP mail sync', ''),
                    $now,
                    null,
                    $mail->id,
                    ['category' => $mail->category],
                ));
            }
        });
        return true;
    }

    /**
     * The extractor's reading of the first PDF, only for a 'Supplier invoice' with a supplier. Never throws: the sync
     * must not fail because of extraction, and "AI not connected" (nothing read) stores nothing.
     *
     * @param list<\TriCab\Core\Finance\Ap2\Domain\Ap2Supplier> $suppliers
     * @param list<Ap2MailAttachment> $rows
     * @param array<string, string> $bytesOf attachment row id => bytes
     */
    private function extract(string $category, ?string $supplierId, array $suppliers, array $rows, array $bytesOf): ?Ap2MailExtraction
    {
        if ($category !== 'Supplier invoice' || $supplierId === null) {
            return null;
        }
        $supplier = null;
        foreach ($suppliers as $candidate) {
            if ($candidate->id === $supplierId) {
                $supplier = $candidate;
            }
        }
        if ($supplier === null) {
            return null;
        }
        foreach ($rows as $row) {
            if (!self::isPdf($row)) {
                continue;
            }
            try {
                $read = $this->extractor->extract($bytesOf[$row->id], $row->contentType !== '' ? $row->contentType : 'application/pdf', $row->name, $supplier->name);
            } catch (Ap2AiUnavailable) {
                return null;
            } catch (Throwable $e) {
                error_log('Tri-AP mail sync extraction failed: ' . $e::class);

                return null;
            }

            return $read->isEmpty() ? null : new Ap2MailExtraction($row->id, $row->name, $supplier->id, $supplier->name, $read);
        }

        return null;
    }

    private static function isPdf(Ap2MailAttachment $a): bool
    {
        return strtolower($a->contentType) === 'application/pdf' || preg_match('/\.pdf$/i', $a->name) === 1;
    }

    /** @return list<array{name: string, type: string, bytes: string, graphId: string}> */
    private function readAttachments(MailboxMessageSummary $summary, ?int $userId): array
    {
        if (!$summary->hasAttachments) {
            return [];
        }
        $out = [];
        foreach ($this->reader->listAttachments(Module::APP_AP2, $this->mailbox, $summary->id, $userId) as $listed) {
            if ($listed->isInline || $listed->size > self::MAX_ATTACHMENT_BYTES || count($out) >= self::MAX_ATTACHMENTS) {
                continue;
            }
            $file = $this->reader->getAttachment(Module::APP_AP2, $this->mailbox, $summary->id, $listed->id, $userId);
            $out[] = ['name' => $listed->name, 'type' => $listed->contentType, 'bytes' => $file->content, 'graphId' => $listed->id];
        }

        return $out;
    }

    /**
     * @param list<string> $names
     * @param list<\TriCab\Core\Finance\Ap2\Domain\Ap2Supplier> $suppliers
     */
    private function classify(MailboxMessageSummary $summary, string $text, array $names, array $suppliers): Ap2MailClassification
    {
        try {
            return $this->classifier->classify($summary->subject, $summary->fromAddress, $text, $names, Ap2SupplierMatcher::refs($suppliers));
        } catch (Ap2AiUnavailable) {
            return Ap2MailClassification::none();
        }
    }

    /** The original Cc list, less our own mailbox, as a `;`-separated line. */
    private function copies(MailboxMessage $message): string
    {
        $out = [];
        foreach ($message->cc as $recipient) {
            $a = Ap2SupplierMatcher::address($recipient->address);
            if ($a !== '' && $a !== strtolower($this->mailbox) && !in_array($a, $out, true)) {
                $out[] = $a;
            }
        }

        return mb_substr(implode('; ', $out), 0, 1000);
    }

    /** The message as plain text: HTML is flattened (scripts and styles dropped, line breaks kept). */
    public static function text(MailboxMessage $message): string
    {
        $body = $message->body;
        if ($message->bodyType === MailboxMessage::BODY_HTML) {
            $body = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', '', $body) ?? $body;
            $body = preg_replace('~<\s*br\s*/?>|</\s*(p|div|tr|li|h[1-6]|table)\s*>~i', "\n", $body) ?? $body;
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $body = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $body);
        $body = preg_replace('/[ \t]+/', ' ', $body) ?? $body;
        $body = preg_replace('/ ?\n ?/', "\n", $body) ?? $body;
        $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;

        return mb_substr(trim($body), 0, 200000);
    }
}
