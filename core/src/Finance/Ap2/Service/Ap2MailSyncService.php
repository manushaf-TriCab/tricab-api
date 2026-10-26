<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateInterval;
use DateTimeImmutable;
use Throwable;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2MailClassification;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceExtractor;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailClassifier;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;
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
 *  - Window: `from`/`to` are Sydney calendar days (at most 31 days, at most 500 messages, the shared reader's rules);
 *    without them it reads from a day before the last successful run (or 7 days back the first time) up to now.
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
 *    `complete`, `cancel`), each request bounded by {@see BATCH_SECONDS} / {@see BATCH_MAX}; {@see sync()} loops the same
 *    code in-process for the scheduled job. One Running run at a time; stale after {@see STALE_MINUTES}.
 */
final class Ap2MailSyncService
{
    public const KIND = 'mail';

    public const DEFAULT_DAYS = 7;

    public const MAX_ATTACHMENTS = 20;

    public const MAX_ATTACHMENT_BYTES = 20971520;

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
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2HistoryStore $history,
        private readonly FinanceMailboxReader $reader,
        private readonly Ap2FileService $files,
        private readonly Ap2MailClassifier $classifier,
        private readonly Ap2InvoiceExtractor $extractor,
        private readonly Ap2Transactions $transactions,
        private readonly ApTime $time,
        private readonly string $mailbox,
        private readonly int $batchSeconds = self::BATCH_SECONDS,
        private readonly int $batchMax = self::BATCH_MAX,
        private readonly int $jobSeconds = self::JOB_SECONDS,
    ) {
    }

    /**
     * The client-driven sync behind `POST /mail/sync`: `{mode: start|process|complete|cancel, ...}`.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function handle(?Ap2Actor $actor, array $body): array
    {
        $mode = $body['mode'] ?? null;

        return match ($mode) {
            'start' => $this->start($actor, $body),
            'resume' => $this->resume($actor, $body),
            'process' => $this->processBatch($actor, $body),
            'complete' => $this->close($body, Ap2SyncRun::SUCCEEDED),
            'cancel' => $this->close($body, Ap2SyncRun::CANCELLED),
            default => throw new Ap2Invalid('mode must be start, resume, process, complete or cancel', Ap2Failure::details(['mode must be start, resume, process, complete or cancel'])),
        };
    }

    /**
     * `start`: the window's messages not yet stored, and a Running row for them.
     *
     * @param array<string, mixed> $body `{from?, to?}`
     * @return array{runId: string, status: string, from: string, to: string, fetched: int, total: int, pending: list<string>, truncated: bool}
     */
    private function start(?Ap2Actor $actor, array $body): array
    {
        [$fromDay, $toDay] = $this->days($body);
        $now = $this->time->now();
        [$start, $end] = $this->window($fromDay, $toDay, $now);
        $open = $this->open($actor?->userId, $start, $end, $fromDay, $toDay);

        return [
            'runId' => $open['run']->id,
            'status' => 'Running',
            'from' => $fromDay ?? $start->format('Y-m-d'),
            'to' => $toDay ?? $end->format('Y-m-d'),
            'fetched' => $open['fetched'],
            'total' => count($open['pending']),
            'pending' => $open['pending'],
            'truncated' => $open['fetched'] >= self::LIST_MAX,
        ];
    }

    /**
     * `resume`: the pending ids of a Running run again (the page was reloaded and lost its list): the run's window is
     * listed afresh and what is already stored (by this run or any) is left out.
     *
     * @param array<string, mixed> $body `{runId}`
     * @return array<string, mixed>
     */
    private function resume(?Ap2Actor $actor, array $body): array
    {
        $run = $this->liveRun($body);
        $fromDay = $run->fromDate;
        $toDay = $run->toDate;
        [$start, $end] = $this->window($fromDay, $toDay, $this->time->now());
        $listed = $this->listFailing(fn (): array => $this->reader->listMessages(Module::APP_AP2, $this->mailbox, $start, $end, self::LIST_MAX, $actor?->userId, 'inbox'));
        $pending = [];
        foreach ($listed as $summary) {
            if (!$this->mail->hasGraphMessage($this->mailbox, $summary->id)) {
                $pending[] = $summary->id;
            }
        }
        $run = $run->with(fetched: $run->processed + count($pending), updatedAt: $this->time->now());
        $this->mail->saveProgress($run);

        return [
            'runId' => $run->id,
            'status' => $run->status,
            'from' => $fromDay,
            'to' => $toDay,
            'fetched' => count($listed),
            'total' => $run->fetched,
            'pending' => $pending,
            'truncated' => count($listed) >= self::LIST_MAX,
            'run' => ['total' => $run->fetched, 'processed' => $run->processed, 'created' => $run->created, 'skipped' => $run->skipped, 'failed' => $run->failed],
        ];
    }

    /**
     * `process`: one bounded batch of `ids` (the leading ones, in order) of a Running run.
     *
     * @param array<string, mixed> $body `{runId, ids}`
     * @return array<string, mixed>
     */
    private function processBatch(?Ap2Actor $actor, array $body): array
    {
        $run = $this->liveRun($body);
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
            'done' => $remaining === 0 || $run->status !== Ap2SyncRun::RUNNING,
            'cancelled' => $run->status === Ap2SyncRun::CANCELLED,
            'run' => ['total' => $run->fetched, 'processed' => $run->processed, 'created' => $run->created, 'skipped' => $run->skipped, 'failed' => $run->failed],
        ];
    }

    /**
     * `complete` / `cancel`: closes a run. Closing a run already closed the same way changes nothing.
     *
     * @param array<string, mixed> $body `{runId}`
     * @return array<string, mixed>
     */
    private function close(array $body, string $status): array
    {
        $run = $this->findRun($body);
        if ($run->isRunning()) {
            $note = $run->failed > 0 ? $run->failed . ' message(s) failed: ' . ($run->error ?? '') : $run->error;
            $run = $run->with(status: $status, error: $note === null ? null : mb_substr($note, 0, 1000), finishedAt: $this->time->now(), updatedAt: $this->time->now());
            $this->mail->finishRun($run);
        } elseif ($run->status !== $status) {
            throw new Ap2Conflict('This sync is already ' . strtolower($run->status), Ap2Failure::details(['This sync is already ' . strtolower($run->status)]) + ['runId' => $run->id]);
        }

        return ['runId' => $run->id, 'status' => $run->status, 'total' => $run->fetched, 'processed' => $run->processed, 'created' => $run->created, 'skipped' => $run->skipped, 'failed' => $run->failed];
    }

    /**
     * The in-process sync of the scheduled job (and of a caller with no browser): the same service, looped within its own
     * budget. What the budget leaves is picked up by the next run (dedupe).
     *
     * @param array<string, mixed> $body `{from?, to?}`
     * @return array{fetched: int, created: int, skipped: int, failed: int, truncated: bool}
     */
    public function sync(?Ap2Actor $actor, array $body): array
    {
        [$fromDay, $toDay] = $this->days($body, false);
        $now = $this->time->now();
        [$start, $end] = $this->window($fromDay, $toDay, $now, true);
        $userId = $actor?->userId;
        $open = $this->open($userId, $start, $end, $fromDay, $toDay);
        $fetched = $open['fetched'];
        $skipped = $fetched - count($open['pending']);
        try {
            $result = $this->runIds($open['run'], $open['pending'], PHP_INT_MAX, $this->jobSeconds, $userId);
        } catch (Throwable $failure) {
            $run = $this->mail->findRun($open['run']->id) ?? $open['run'];
            $this->mail->finishRun($run->with(status: Ap2SyncRun::FAILED, error: mb_substr($failure->getMessage(), 0, 1000), finishedAt: $this->time->now(), updatedAt: $this->time->now()));

            throw $failure;
        }
        $run = $result['run'];
        $left = count($open['pending']) - $result['consumed'];
        $notes = [];
        if ($result['failed'] > 0) {
            $notes[] = $result['failed'] . ' message(s) failed: ' . ($result['errors'][0] ?? '');
        }
        if ($left > 0 && $run->isRunning()) {
            $notes[] = $left . ' message(s) left for the next run';
        }
        if ($run->isRunning()) {
            $this->mail->finishRun($run->with(status: Ap2SyncRun::SUCCEEDED, error: $notes === [] ? null : mb_substr(implode('; ', $notes), 0, 1000), finishedAt: $this->time->now(), updatedAt: $this->time->now()));
        }

        return ['fetched' => $fetched, 'created' => $result['created'], 'skipped' => $skipped + $result['skipped'], 'failed' => $result['failed'], 'truncated' => $fetched >= self::LIST_MAX];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: ?string, 1: ?string}
     */
    private function days(array $body, bool $webDefault = true): array
    {
        $input = new Ap2Input($body);
        $input->date('from');
        $input->date('to');
        if ($input->issues() !== []) {
            throw new Ap2Invalid('The window is invalid', Ap2Failure::details($input->issues()));
        }
        $values = $input->values();
        $fromDay = is_string($values['from'] ?? null) ? $values['from'] : null;
        $toDay = is_string($values['to'] ?? null) ? $values['to'] : null;
        if ($webDefault && ($fromDay === null || $toDay === null)) {
            $today = $this->time->today();
            $toDay ??= $today;
            $fromDay ??= (new DateTimeImmutable($toDay))->sub(new DateInterval('P' . (self::DEFAULT_DAYS - 1) . 'D'))->format('Y-m-d');
        }
        if ($fromDay !== null && $toDay !== null) {
            if ($fromDay > $toDay) {
                throw new Ap2Invalid('The window is invalid', Ap2Failure::details(['from must not be after to']));
            }
            $span = (int) (new DateTimeImmutable($fromDay))->diff(new DateTimeImmutable($toDay))->days + 1;
            if ($span > self::MAX_DAYS) {
                throw new Ap2Invalid('The window is invalid', Ap2Failure::details(['The window must be at most ' . self::MAX_DAYS . ' days']));
            }
        }

        return [$fromDay, $toDay];
    }

    /**
     * Refuses while another sync is running (409 with its id); a running row with no progress for {@see STALE_MINUTES}
     * (or none recorded, a row from before the column) is marked Failed and does not block. Then lists the window and
     * records the run with the messages not yet stored.
     *
     * @return array{run: Ap2SyncRun, pending: list<string>, fetched: int}
     */
    private function open(?int $userId, DateTimeImmutable $start, DateTimeImmutable $end, ?string $fromDay, ?string $toDay): array
    {
        $now = $this->time->now();
        $running = $this->mail->runningRun(self::KIND);
        if ($running !== null) {
            if (!$running->isStale($now, self::STALE_MINUTES)) {
                throw new Ap2Conflict('A sync is already running', Ap2Failure::details(['A sync is already running']) + ['runId' => $running->id, 'run' => ['runId' => $running->id, 'status' => $running->status, 'total' => $running->fetched, 'processed' => $running->processed, 'created' => $running->created, 'skipped' => $running->skipped, 'failed' => $running->failed, 'from' => $running->fromDate, 'to' => $running->toDate]]);
            }
            $this->mail->finishRun($running->with(status: Ap2SyncRun::FAILED, error: 'Abandoned: no progress for ' . self::STALE_MINUTES . ' minutes', finishedAt: $now));
        }

        $run = new Ap2SyncRun(bin2hex(random_bytes(16)), self::KIND, Ap2SyncRun::RUNNING, $now, $fromDay ?? $start->format('Y-m-d'), $toDay ?? $end->format('Y-m-d'), startedByUserId: $userId, updatedAt: $now);
        $this->mail->beginRun($run);
        try {
            $listed = $this->listFailing(fn (): array => $this->reader->listMessages(Module::APP_AP2, $this->mailbox, $start, $end, self::LIST_MAX, $userId, 'inbox'));
        } catch (Throwable $failure) {
            $this->mail->finishRun($run->with(status: Ap2SyncRun::FAILED, error: mb_substr($failure->getMessage(), 0, 1000), finishedAt: $this->time->now(), updatedAt: $this->time->now()));

            throw $failure;
        }
        $pending = [];
        foreach ($listed as $summary) {
            if (!$this->mail->hasGraphMessage($this->mailbox, $summary->id)) {
                $pending[] = $summary->id;
            }
        }
        $run = $run->with(fetched: count($pending), updatedAt: $this->time->now());
        $this->mail->finishRun($run);

        return ['run' => $run, 'pending' => $pending, 'fetched' => count($listed)];
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
                $failure instanceof FinanceMailRefused => new Ap2Conflict('Tri-AP2 may not read ' . $this->mailbox, Ap2Failure::details([$failure->getMessage()]), $failure),
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
                throw new Ap2Conflict('Tri-AP2 may not read ' . $this->mailbox, Ap2Failure::details([$refused->getMessage()]), $refused);
            } catch (Throwable $failure) {
                $outcome = 'failed';
                $errors[] = mb_substr($failure::class . ': ' . $failure->getMessage(), 0, 300);
                error_log('Tri-AP2 mail sync: message failed: ' . $failure::class);
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

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    private function window(?string $fromDay, ?string $toDay, DateTimeImmutable $now, bool $sinceLast = false): array
    {
        [$dayStart, $dayEnd] = $this->time->dayBounds($fromDay, $toDay);
        $end = $dayEnd === null ? $now : min($now, $dayEnd->sub(new DateInterval('PT1S')));
        if ($dayStart !== null) {
            $start = $dayStart;
        } else {
            $last = $sinceLast ? $this->mail->lastSucceededRun(self::KIND) : null;
            $start = $last === null ? $end->sub(new DateInterval('P' . self::DEFAULT_DAYS . 'D')) : $last->startedAt->sub(new DateInterval('P1D'));
            // Never ask for more than the reader's 31 days.
            $floor = $end->sub(new DateInterval('P31D'))->add(new DateInterval('PT1H'));
            $start = max($start, $floor);
        }
        if ($start > $end) {
            throw new Ap2Invalid('The window is invalid', Ap2Failure::details(['from must not be after to']));
        }

        return [$start, $end];
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
                    new Ap2Person(0, 'Tri-AP2 mail sync', ''),
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
                error_log('Tri-AP2 mail sync extraction failed: ' . $e::class);

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
