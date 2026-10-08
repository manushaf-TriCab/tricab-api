<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use Closure;
use DateTimeImmutable;
use Throwable;
use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Contract\ArScanStore;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArAlreadyScanned;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Finance\Ar\Domain\ArForbidden;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArKeptAttachment;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArScanRun;
use TriCab\Core\Finance\Ar\Domain\ArUpstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;

/**
 * Mail in `ar@` becomes records (T5.7): the source's `_ingest_message`, `_scan_from_watermark` and `scan_emails`.
 *
 * - Only the Inbox is read (Q2), by Graph immutable id; a message already stored, or deleted for good, is skipped.
 * - **The watermark advances only over an unbroken run** of messages now stored, oldest first; one that fails to
 *   store (or cannot be read) blocks it for the rest of the run. **A period scan never moves it.**
 * - A run holds the mailbox's claim while it is `running`, so the job and an operator cannot ingest at once; the
 *   unique message-id index is the backstop.
 * - A model or attachment failure never fails a scan: it is counted, recorded and logged by the AI service.
 */
final class ArScanService
{
    public const FOLDER = 'inbox';
    public const CLAIM_MINUTES = 30;
    public const FIRST_RUN_DAYS = 7;
    public const SLICE_DAYS = 31;
    public const BODY_CHARS = 20000;
    public const MIN_IMAGE_BYTES = 25600;
    public const REMITTANCE_CATEGORY = 'remittance_advice';

    /** The extensions worth sending to a model, and the type each is sent as. */
    private const MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
    ];

    /** @var Closure(): string */
    private readonly Closure $newId;

    /**
     * @param int $listCap the most one listing may return before the window is split (the reader's ceiling is 500)
     * @param (callable(): string)|null $newId new 32-hex ids
     */
    public function __construct(
        private readonly ArRecordStore $records,
        private readonly ArScanStore $scans,
        private readonly FinanceMailboxReader $mailbox,
        private readonly ArAiService $ai,
        private readonly ArAttachmentService $attachments,
        private readonly ArTime $time,
        private readonly string $mailboxAddress,
        private readonly int $listCap = 500,
        ?callable $newId = null,
    ) {
        $this->newId = Closure::fromCallable($newId ?? static fn (): string => bin2hex(random_bytes(16)));
    }

    // ==================================================================================
    // The watermark scan: the job, and the operator with no mode
    // ==================================================================================

    /**
     * @return array<string, mixed> the source's `_scan_from_watermark` answer
     */
    public function scanFromWatermark(?ArActor $actor): array
    {
        $now = $this->time->now();
        $run = $this->start($actor, null, null);
        $watermark = $this->scans->latestWatermark($this->mailboxAddress);
        $from = $watermark ?? $now->modify('-' . self::FIRST_RUN_DAYS . ' days');

        try {
            $messages = $this->listWindow($from, $now, $actor?->userId);
        } catch (ArFailure $failure) {
            $this->fail($run, $failure);
            throw $failure;
        }
        usort($messages, static fn (MailboxMessageSummary $a, MailboxMessageSummary $b): int => $a->receivedAt <=> $b->receivedAt);

        $new = $watermark;
        $blocked = false;
        $totals = ['new' => 0, 'skipped' => 0, 'failed' => 0, 'kept' => 0, 'extracted' => 0, 'attachment_failures' => 0];
        $errors = [];
        foreach ($messages as $summary) {
            try {
                $outcome = $this->ingest($summary->id, $actor?->userId);
            } catch (Throwable $unexpected) {
                // Nothing in ingest should throw; if it does, end the run so it does not hold the mailbox until its claim expires.
                $this->fail($run, new ArUpstream('The scan stopped on an unexpected error (' . $unexpected::class . ')', ArUpstream::MAILBOX, $unexpected));
                throw $unexpected;
            }
            if ($outcome['failed']) {
                $blocked = true;
            } elseif (!$blocked && ($new === null || $summary->receivedAt > $new)) {
                $new = $summary->receivedAt;
            }
            $totals['new'] += $outcome['new_records'];
            $totals['skipped'] += $outcome['skipped_records'];
            $totals['failed'] += $outcome['failed_enrichments'];
            $totals['kept'] += $outcome['attachments_kept'];
            $totals['extracted'] += $outcome['attachments_extracted'];
            $totals['attachment_failures'] += $outcome['attachment_failures'];
            array_push($errors, ...$outcome['errors']);
            $this->scans->bump($run->id, [], $this->claimUntil());
        }

        $final = $run->with(totalFetched: count($messages), newRecords: $totals['new'], skippedRecords: $totals['skipped'], failedEnrichments: $totals['failed'], errors: self::joinErrors($errors));
        $this->scans->finish($run->id, ArScanRun::COMPLETED, $this->time->now(), $new, $final);

        return [
            'success' => true,
            'run' => $this->present($this->scans->find($run->id) ?? $final),
            'total_fetched' => count($messages),
            'new_records' => $totals['new'],
            'skipped_records' => $totals['skipped'],
            'failed_enrichments' => $totals['failed'],
            'attachments_kept' => $totals['kept'],
            'attachments_extracted' => $totals['extracted'],
            'attachment_failures' => $totals['attachment_failures'],
        ];
    }

    // ==================================================================================
    // The operator's three modes
    // ==================================================================================

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function handle(ArActor $actor, array $body): array
    {
        $mode = $body['mode'] ?? null;

        return match (true) {
            $mode === null || $mode === '' => $this->scanFromWatermark($actor),
            $mode === 'list' => $this->listPeriod($actor, $body),
            $mode === 'process' => $this->process($actor, $body),
            $mode === 'complete' => $this->complete($body),
            default => throw new ArInvalid('Unknown mode: ' . (is_scalar($mode) ? (string) $mode : '?')),
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function listPeriod(ArActor $actor, array $body): array
    {
        $from = is_string($body['fromDate'] ?? null) ? trim($body['fromDate']) : '';
        $to = is_string($body['toDate'] ?? null) ? trim($body['toDate']) : '';
        if ($from === '' || $to === '') {
            throw new ArInvalid('fromDate and toDate are required');
        }
        $from = ArRules::dateOrNull($from, 'Dates must use YYYY-MM-DD') ?? '';
        $to = ArRules::dateOrNull($to, 'Dates must use YYYY-MM-DD') ?? '';
        if ($from > $to) {
            throw new ArInvalid('From date cannot be after to date');
        }
        // Re-scanning a period already scanned is how duplicate records get in: confirm first.
        if (ArRules::flag($body['confirmRescan'] ?? null) === false) {
            $prior = $this->scans->overlapping($from, $to);
            if ($prior !== []) {
                throw new ArAlreadyScanned(
                    'This period has already been scanned. Scanning it again may create duplicate records.',
                    ['priorRuns' => array_map(static fn (ArScanRun $r): array => [
                        'id' => $r->id,
                        'fromDate' => $r->fromDate,
                        'toDate' => $r->toDate,
                        'scannedAt' => ArPresenter::iso($r->startedAt),
                        'scannedBy' => $r->requestedBy !== '' ? $r->requestedBy : 'Unknown user',
                        'totalMessages' => $r->totalMessages,
                        'newRecords' => $r->newRecords,
                        'status' => $r->status,
                    ], $prior)],
                );
            }
        }

        $run = $this->start($actor, $from, $to);
        [$start, $end] = $this->time->dayBounds($from, $to);
        if ($start === null || $end === null) {
            throw new ArInvalid('Dates must use YYYY-MM-DD');
        }
        try {
            $messages = $this->listWindow($start, $end->modify('-1 second'), $actor->userId);
        } catch (ArFailure $failure) {
            $this->fail($run, $failure);
            throw $failure;
        }
        $this->scans->bump($run->id, ['total_messages' => count($messages)], $this->claimUntil());

        return [
            'scanRunId' => $run->id,
            'truncated' => false,
            'messages' => array_map(static fn (MailboxMessageSummary $m): array => [
                'id' => $m->id,
                'subject' => $m->subject,
                'from' => $m->fromAddress,
                'receivedDateTime' => ArPresenter::instant($m->receivedAt),
                'hasAttachments' => $m->hasAttachments,
            ], $messages),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function process(ArActor $actor, array $body): array
    {
        $messageId = is_string($body['messageId'] ?? null) ? trim($body['messageId']) : '';
        if ($messageId === '') {
            throw new ArInvalid('messageId is required');
        }
        $runId = is_string($body['scanRunId'] ?? null) && $body['scanRunId'] !== '' ? $body['scanRunId'] : null;

        $outcome = $this->ingest($messageId, $actor->userId);
        if ($outcome['fetch_error'] !== null) {
            if ($runId !== null) {
                $this->scans->bump($runId, ['processed_messages' => 1, 'failed_enrichments' => 1], $this->claimUntil());
            }
            throw new ArUpstream('Graph fetch failed: ' . $outcome['fetch_error'], ArUpstream::MAILBOX);
        }
        if ($runId !== null) {
            $this->scans->bump($runId, [
                'processed_messages' => 1,
                'total_fetched' => 1,
                'new_records' => $outcome['new_records'],
                'skipped_records' => $outcome['skipped_records'],
                'failed_enrichments' => $outcome['failed_enrichments'],
            ], $this->claimUntil());
        }

        return [
            'newRecords' => $outcome['new_records'],
            'skipped' => $outcome['skipped_records'],
            'errors' => $outcome['failed_enrichments'] + $outcome['attachment_failures'],
            'category' => $outcome['category'],
            'receivedAt' => $outcome['received_at'],
        ];
    }

    /**
     * A period scan never moves the watermark, whatever the client sends: it does not establish that everything up
     * to a point has been scanned. The next watermark scan lists what the period scan stored and skips it.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function complete(array $body): array
    {
        $id = is_string($body['scanRunId'] ?? null) ? $body['scanRunId'] : '';
        $run = $id !== '' ? $this->scans->find($id) : null;
        if ($run === null) {
            throw new ArNotFound('Scan run not found');
        }
        $this->scans->finish($run->id, ArRules::flag($body['stopped'] ?? null) ? ArScanRun::STOPPED : ArScanRun::COMPLETED, $this->time->now(), null, $run);

        return ['success' => true, 'run' => $this->present($this->scans->find($run->id) ?? $run)];
    }

    /**
     * `GET /scan-runs`.
     *
     * @return array<string, mixed>
     */
    public function runs(mixed $limit): array
    {
        $n = is_numeric($limit) ? (int) $limit : 20;
        $n = max(1, min(100, $n));

        return ['runs' => array_map($this->present(...), $this->scans->recent($n))];
    }

    // ==================================================================================
    // Ingest
    // ==================================================================================

    /**
     * One message: skip it if known, else read it, enrich it, store it and keep its attachments.
     *
     * @return array{new_records: int, skipped_records: int, failed_enrichments: int, attachments_kept: int, attachments_extracted: int, attachment_failures: int, category: ?string, received_at: string, failed: bool, fetch_error: ?string, errors: list<string>}
     */
    private function ingest(string $messageId, ?int $actorId): array
    {
        $outcome = [
            'new_records' => 0, 'skipped_records' => 0, 'failed_enrichments' => 0, 'attachments_kept' => 0,
            'attachments_extracted' => 0, 'attachment_failures' => 0, 'category' => null, 'received_at' => '',
            'failed' => false, 'fetch_error' => null, 'errors' => [],
        ];
        if ($this->records->messageIdKnown($messageId) || $this->records->isTombstoned($messageId)) {
            $outcome['skipped_records'] = 1;

            return $outcome;
        }

        try {
            $message = $this->mailbox->getMessage(Module::APP_AR, $this->mailboxAddress, $messageId, $actorId);
        } catch (Throwable $failure) {
            // A message we could not read is still to be scanned: the watermark must not pass it.
            $outcome['failed'] = true;
            $outcome['fetch_error'] = self::safe($failure);
            $outcome['errors'][] = 'read ' . substr(hash('sha256', $messageId), 0, 8) . ': ' . self::safe($failure);

            return $outcome;
        }
        $summary = $message->summary;
        $outcome['received_at'] = ArPresenter::instant($summary->receivedAt);
        $subject = $summary->subject !== '' ? $summary->subject : '(no subject)';
        $text = ArMailText::of($message);

        $category = 'other';
        $method = 'email';
        $status = ArRecord::STATUS_NEEDS_REVIEW;
        $confidence = null;
        $customer = $summary->fromName;
        $aiSummary = '';
        $response = '';
        try {
            $decision = $this->ai->classify($summary->fromName, $summary->fromAddress, $subject, $text);
            $category = $decision->followUpCategory;
            $method = $decision->proposedResponseType;
            $status = $decision->followUpStatus === ArRecord::STATUS_IN_PROGRESS ? ArRecord::STATUS_IN_PROGRESS : ArRecord::STATUS_NEEDS_REVIEW;
            $confidence = max(0.0, min(1.0, $decision->confidence));
        } catch (ArFailure $failure) {
            $outcome['failed_enrichments'] = 1;
            $outcome['errors'][] = 'Jev error: ' . $failure->getMessage();
        }
        try {
            $prose = $this->ai->prose($summary->fromName, $summary->fromAddress, $subject, $text);
            $customer = $prose->customerName !== '' ? $prose->customerName : $customer;
            $aiSummary = $prose->aiSummary;
            $response = $prose->proposedResponse;
        } catch (ArFailure $failure) {
            $outcome['failed_enrichments'] = 1;
            $status = ArRecord::STATUS_NEEDS_REVIEW;
            $outcome['errors'][] = 'Gemini error: ' . $failure->getMessage();
        }

        $now = $this->time->now();
        $record = new ArRecord(
            id: ($this->newId)(),
            emailDate: $summary->receivedAt,
            customerName: mb_substr($customer, 0, 400),
            senderEmail: mb_substr($summary->fromAddress, 0, 320),
            senderName: mb_substr($summary->fromName, 0, 400),
            subject: mb_substr($subject, 0, 400),
            body: mb_substr($text, 0, self::BODY_CHARS),
            aiSummary: $aiSummary,
            followUpCategory: mb_substr($category, 0, 100),
            assignedUserId: null,
            assignedName: '',
            proposedResponseType: mb_substr($method, 0, 100),
            proposedResponse: $response,
            followUpStatus: $status,
            dueDate: null,
            finalOutcome: '',
            sourceMessageId: $messageId,
            messageUnreachable: false,
            mailbox: $this->mailboxAddress,
            classificationConfidence: $confidence,
            status: 'new',
            assignedSalesRepEmail: '',
            creditNoteRequestId: null,
            reminderSentAt: null,
            deletedAt: null,
            deletedByUserId: null,
            deletedBy: '',
            createdAt: $now,
            updatedAt: $now,
        );
        try {
            $this->records->insert($record);
        } catch (ArConflict) {
            // Someone else stored it between the check and the insert: it is there, which is all a scan needs.
            $outcome['skipped_records'] = 1;
            $outcome['failed_enrichments'] = 0;

            return $outcome;
        } catch (Throwable $failure) {
            error_log('Tri-AR scan: a record could not be stored (' . $failure::class . ')');
            $outcome['skipped_records'] = 1;
            $outcome['failed'] = true;
            $outcome['errors'][] = 'store: ' . $failure::class;

            return $outcome;
        }
        $outcome['new_records'] = 1;
        $outcome['category'] = $record->followUpCategory;

        if ($summary->hasAttachments) {
            $this->captureAttachments($record, $subject, $actorId, $outcome);
        }

        return $outcome;
    }

    /**
     * Keeps a message's supported attachments and, for remittance advice, reads them. Never fatal.
     *
     * @param array{new_records: int, skipped_records: int, failed_enrichments: int, attachments_kept: int, attachments_extracted: int, attachment_failures: int, category: ?string, received_at: string, failed: bool, fetch_error: ?string, errors: list<string>} $outcome
     */
    private function captureAttachments(ArRecord $record, string $subject, ?int $actorId, array &$outcome): void
    {
        $messageId = (string) $record->sourceMessageId;
        try {
            $listed = $this->mailbox->listAttachments(Module::APP_AR, $this->mailboxAddress, $messageId, $actorId);
        } catch (Throwable $failure) {
            ++$outcome['attachment_failures'];
            $outcome['errors'][] = 'list_attachments: ' . self::safe($failure);

            return;
        }
        $extractHere = $record->followUpCategory === self::REMITTANCE_CATEGORY;
        foreach ($listed as $attachment) {
            $mime = self::supportedType($attachment);
            if ($mime === null) {
                continue;
            }
            try {
                $file = $this->mailbox->getAttachment(Module::APP_AR, $this->mailboxAddress, $messageId, $attachment->id, $actorId);
                $rowId = $this->attachments->keep(
                    $record->id,
                    $attachment->name !== '' ? $attachment->name : 'attachment',
                    $mime,
                    $file->content,
                    $extractHere ? ArKeptAttachment::PENDING : ArKeptAttachment::SKIPPED,
                );
            } catch (Throwable $failure) {
                ++$outcome['attachment_failures'];
                $outcome['errors'][] = 'save ' . $attachment->name . ': ' . self::safe($failure);
                continue;
            }
            ++$outcome['attachments_kept'];
            if (!$extractHere) {
                continue;
            }
            try {
                $extraction = $this->ai->extractRemittance($subject, $record->senderEmail, $attachment->name, $mime, $file->content);
                $lineIds = array_map(fn (): string => ($this->newId)(), $extraction->lines);
                $this->records->saveExtraction($rowId, $record->id, $extraction, $this->time->now(), $lineIds);
                ++$outcome['attachments_extracted'];
            } catch (Throwable $failure) {
                ++$outcome['attachment_failures'];
                $outcome['errors'][] = 'extract ' . $attachment->name . ': ' . self::safe($failure);
                $this->records->markExtractionFailed($rowId, self::safe($failure), $this->time->now());
            }
        }
    }

    /** The type to send a model, or null for an attachment not worth reading: inline, odd extension, or a small image (a logo or beacon). */
    public static function supportedType(MailboxAttachmentSummary $attachment): ?string
    {
        if ($attachment->isInline) {
            return null;
        }
        $extension = strtolower(pathinfo($attachment->name, PATHINFO_EXTENSION));
        $mime = self::MIME_BY_EXTENSION[$extension] ?? null;
        if ($mime === null || ($extension !== 'pdf' && $attachment->size < self::MIN_IMAGE_BYTES)) {
            return null;
        }

        return $mime;
    }

    // ==================================================================================
    // Listing, runs, helpers
    // ==================================================================================

    /**
     * The Inbox messages received in [$from, $to]. A window over 31 days is read in 31-day slices, and a slice that
     * fills the listing cap is split until it fits, so the oldest mail is never silently dropped.
     *
     * @return list<MailboxMessageSummary>
     */
    private function listWindow(DateTimeImmutable $from, DateTimeImmutable $to, ?int $actorId): array
    {
        $found = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $sliceEnd = min($to, $cursor->modify('+' . self::SLICE_DAYS . ' days'));
            foreach ($this->listSplitting($cursor, $sliceEnd, $actorId) as $message) {
                $found[$message->id] = $message;
            }
            $cursor = $sliceEnd->modify('+1 second');
        }

        return array_values($found);
    }

    /**
     * @return list<MailboxMessageSummary>
     */
    private function listSplitting(DateTimeImmutable $from, DateTimeImmutable $to, ?int $actorId): array
    {
        try {
            $messages = $this->mailbox->listMessages(Module::APP_AR, $this->mailboxAddress, $from, $to, $this->listCap, $actorId, self::FOLDER);
        } catch (FinanceMailRefused $refused) {
            throw new ArForbidden($refused->getMessage(), [], $refused);
        } catch (MailboxReadFailed | FinanceMailException $failure) {
            throw new ArUpstream('Graph fetch failed: ' . $failure->getMessage(), ArUpstream::MAILBOX, $failure);
        }
        $span = $to->getTimestamp() - $from->getTimestamp();
        if (count($messages) < $this->listCap || $span < 2) {
            return $messages;
        }
        $middle = $from->modify('+' . intdiv($span, 2) . ' seconds');

        return [...$this->listSplitting($from, $middle, $actorId), ...$this->listSplitting($middle->modify('+1 second'), $to, $actorId)];
    }

    private function start(?ArActor $actor, ?string $from, ?string $to): ArScanRun
    {
        $now = $this->time->now();
        $run = new ArScanRun(
            ($this->newId)(), $now, null, $this->mailboxAddress, ArScanRun::RUNNING, null, 0, 0, 0, 0, '', $from, $to,
            $actor?->userId, $actor !== null ? $actor->name : 'Scheduled scan', 0, 0, $this->claimUntil(),
        );
        $this->scans->begin($run, $now);

        return $run;
    }

    private function claimUntil(): DateTimeImmutable
    {
        return $this->time->now()->modify('+' . self::CLAIM_MINUTES . ' minutes');
    }

    private function fail(ArScanRun $run, ArFailure $failure): void
    {
        $this->scans->finish($run->id, ArScanRun::FAILED, $this->time->now(), null, $run->with(errors: mb_substr($failure->getMessage(), 0, 1000)));
    }

    /**
     * @param list<string> $errors
     */
    private static function joinErrors(array $errors): string
    {
        return mb_substr(implode("\n", $errors), 0, 4000);
    }

    private static function safe(Throwable $failure): string
    {
        return $failure instanceof ArFailure || $failure instanceof MailboxReadFailed || $failure instanceof FinanceMailException
            ? mb_substr($failure->getMessage(), 0, 300)
            : $failure::class;
    }

    /**
     * A run as the source's scan-run row.
     *
     * @return array<string, mixed>
     */
    public function present(ArScanRun $r): array
    {
        return [
            'id' => $r->id,
            'started_at' => ArPresenter::iso($r->startedAt),
            'finished_at' => ArPresenter::iso($r->finishedAt),
            'mailbox' => $r->mailbox,
            'status' => $r->status,
            'watermark' => $r->watermark === null ? null : ArPresenter::instant($r->watermark),
            'total_fetched' => $r->totalFetched,
            'new_records' => $r->newRecords,
            'skipped_records' => $r->skippedRecords,
            'failed_enrichments' => $r->failedEnrichments,
            'errors' => $r->errors === '' ? null : $r->errors,
            'from_date' => $r->fromDate,
            'to_date' => $r->toDate,
            'requested_by' => $r->requestedBy,
            'total_messages' => $r->totalMessages,
            'processed_messages' => $r->processedMessages,
        ];
    }
}
