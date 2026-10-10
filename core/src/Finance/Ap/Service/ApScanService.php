<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use TriCab\Core\Finance\Ap\ApAiService;
use TriCab\Core\Finance\Ap\ApDraftEmail;
use TriCab\Core\Finance\Ap\ApExtraction;
use TriCab\Core\Finance\Ap\ApExtractionCompany;
use TriCab\Core\Finance\Ap\Contract\ApEmailStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Contract\ApReferenceStore;
use TriCab\Core\Finance\Ap\Contract\ApScanStore;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApAlreadyScanned;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApEmailArrival;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceCapture;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApReferenceSpec;
use TriCab\Core\Finance\Ap\Domain\ApScanRun;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;

/**
 * Mail in the granted AP mailboxes becomes invoices (`POST /scan`, `app.py` route 49, T6.7): the person picks a period, the
 * page asks for the messages (`list`), calls `process` once for each and then `complete`. Nothing runs on a timer (Q6).
 *
 * - **list**: the window is the local days in the Tri-AP zone; the mailboxes are every one the registry grants Tri-AP to
 *   read, cut to the person's scope; every FOLDER of each is read (no folder argument to the shared reader, which audits each
 *   listing). A period already scanned is a 409 with `priorRuns` unless `confirmRescan` is sent. The run holds the app's one
 *   claim (D76) until `complete`; a run that died holding it is failed once its claim has expired.
 * - **process**: one message. The scope AND the read grant are checked (R6, the reader does the latter); the email is
 *   registered first; each attachment is selected, skipped if captured or tombstoned, hashed (an exact copy of an invoice
 *   makes no model call), classified and extracted by ONE model call, and filed. The email is then classified from what its
 *   files turned out to be (the weakest confidence, every reason, a Verified email keeping its classification), a first-draft
 *   reply is suggested if none is stored, and the run's counters are added to in one statement (R14).
 * - **complete**: closes the run and releases the claim.
 *
 * Safe to run twice: the email is unique by (mailbox, message id), a file already captured or tombstoned is skipped, an exact
 * copy is never filed twice, and an invoice and its file row are written in one transaction (a second request that loses the
 * race for the file row rolls its invoice back and counts nothing). The scan writes no audit event of its own, as the source
 * did; the mailbox reads leave the shared reader's rows.
 */
final class ApScanService
{
    public const CLAIM_MINUTES = 30;
    public const SLICE_DAYS = 31;
    public const PRIOR_RUNS = 20;

    public const DATES_REQUIRED = 'fromDate and toDate are required';
    public const BAD_DATES = 'Dates must use YYYY-MM-DD';
    public const FROM_AFTER_TO = 'From date cannot be after to date';
    public const BAD_MAILBOXES = 'mailboxes must be a list of addresses';
    public const NO_MAILBOX = 'You do not have access to the active AP mailbox';
    public const ALREADY_SCANNED = 'This period has already been scanned. Scanning it again may create duplicate invoices.';
    public const MESSAGE_REQUIRED = 'messageId and mailbox are required';
    public const MESSAGE_TOO_LONG = 'The message id is too long to store';
    public const MAILBOX_FORBIDDEN = 'You do not have access to this mailbox';
    public const RUN_NOT_FOUND = 'Scan run not found';
    public const NO_SUPPORTED_FILE = 'No supported PDF/image invoice attachment; manual verification required';

    /** @var Closure(string): void */
    private readonly Closure $log;

    /**
     * @param int $minAttachmentBytes `FINANCE_AP_MIN_ATTACHMENT_BYTES`: a non-PDF smaller than this is a logo, not an invoice
     * @param int $maxScanMessages `FINANCE_AP_MAX_SCAN_MESSAGES`: the most messages one mailbox lists (newest first); 0 is no cap
     * @param list<string> $internalDomains our own mail domains; empty means the domain of the mailbox being scanned
     * @param int $listCap the most one listing may return before its window is split (the reader's own ceiling is 500)
     * @param (callable(string): void)|null $log written to `error_log` unless a test passes its own
     */
    public function __construct(
        private readonly ApScanStore $scans,
        private readonly FinanceMailboxRegistry $registry,
        private readonly FinanceMailboxReader $mailbox,
        private readonly ApEmailService $emails,
        private readonly ApEmailStore $emailStore,
        private readonly ApInvoiceService $invoices,
        private readonly ApInvoiceStore $invoiceStore,
        private readonly ApAttachmentService $attachments,
        private readonly ApAiService $ai,
        private readonly ApReferenceStore $references,
        private readonly ApTime $time,
        private readonly int $minAttachmentBytes = ApAttachmentRules::MIN_BYTES,
        private readonly int $maxScanMessages = 0,
        private readonly array $internalDomains = [],
        private readonly int $listCap = 500,
        ?callable $log = null,
    ) {
        $this->log = Closure::fromCallable($log ?? static function (string $line): void {
            error_log($line);
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function handle(ApActor $actor, array $body): array
    {
        $mode = $body['mode'] ?? null;

        return match (true) {
            $mode === 'list' => $this->list($actor, $body),
            $mode === 'process' => $this->process($actor, $body),
            $mode === 'complete' => $this->complete($body),
            default => throw new ApInvalid('Unknown mode: ' . (is_scalar($mode) && $mode !== '' ? (string) $mode : 'None')),
        };
    }

    // ==================================================================================
    // list
    // ==================================================================================

    /**
     * @param array<string, mixed> $body
     * @return array{scanRunId: string, messages: list<array<string, mixed>>, mailboxErrors: list<array{mailbox: string, error: string}>}
     */
    private function list(ApActor $actor, array $body): array
    {
        $fromText = $body['fromDate'] ?? null;
        $toText = $body['toDate'] ?? null;
        if (!ApRules::truthy($fromText) || !ApRules::truthy($toText)) {
            throw new ApInvalid(self::DATES_REQUIRED);
        }
        $from = is_string($fromText) ? ApDates::strict(trim($fromText)) : null;
        $to = is_string($toText) ? ApDates::strict(trim($toText)) : null;
        if ($from === null || $to === null) {
            throw new ApInvalid(self::BAD_DATES);
        }
        if ($from > $to) {
            throw new ApInvalid(self::FROM_AFTER_TO);
        }
        $mailboxes = $this->mailboxesFor($actor, $body['mailboxes'] ?? null);

        // Re-scanning a period already scanned is how duplicates get in: the page confirms first ("Scan anyway").
        if (!ApRules::truthy($body['confirmRescan'] ?? null)) {
            $prior = $this->scans->overlapping($from, $to, self::PRIOR_RUNS);
            if ($prior !== []) {
                throw new ApAlreadyScanned(self::ALREADY_SCANNED, ['priorRuns' => array_map(self::priorRun(...), $prior)]);
            }
        }

        [$start, $endExclusive] = $this->time->dayBounds($from, $to);
        if ($start === null || $endExclusive === null) {
            throw new ApInvalid(self::BAD_DATES);
        }
        $end = $endExclusive->modify('-1 second');

        $now = $this->time->now();
        $run = new ApScanRun(
            ApRules::newId(),
            $now,
            $now,
            $actor->userId,
            $actor->name !== '' ? $actor->name : $actor->email,
            $from,
            $to,
            $mailboxes,
            ApScanRun::LISTING,
            $this->claimUntil(),
        );
        $this->scans->begin($run, $now);

        $messages = [];
        $errors = [];
        try {
            foreach ($mailboxes as $mailbox) {
                try {
                    foreach ($this->listMailbox($mailbox, $start, $end, $actor->userId) as $summary) {
                        $messages[] = [
                            'id' => $summary->id,
                            'subject' => $summary->subject,
                            'from' => $summary->fromAddress,
                            'receivedDateTime' => $summary->receivedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                            'mailbox' => $mailbox,
                            'hasAttachments' => $summary->hasAttachments,
                        ];
                    }
                } catch (FinanceMailException | MailboxReadFailed $failure) {
                    ($this->log)(sprintf('Tri-AP scan: listing %s failed (%s)', $mailbox, $failure::class));
                    $errors[] = ['mailbox' => $mailbox, 'error' => mb_substr($failure->getMessage(), 0, 300)];
                }
            }
        } catch (Throwable $unexpected) {
            // Nothing above should throw anything else; if it does, end the run so it does not hold the claim until it expires.
            $this->scans->finish($run->id, ApScanRun::FAILED, $this->time->now());
            throw $unexpected;
        }

        if ($messages === [] && $errors !== []) {
            $this->scans->finish($run->id, ApScanRun::FAILED, $this->time->now(), count($errors));

            throw new ApUpstream($errors[0]['error'], ApUpstream::MAILBOX);
        }
        $this->scans->markRunning($run->id, count($messages), count($errors), $this->claimUntil(), $this->time->now());

        return ['scanRunId' => $run->id, 'messages' => $messages, 'mailboxErrors' => $errors];
    }

    /**
     * The addresses a `list` reads, lower-case: those asked for (or all) that the registry grants Tri-AP to read, each once,
     * cut to the person's scope. One that is not readable is dropped without a word; none left is a refusal.
     *
     * @return list<string>
     */
    private function mailboxesFor(ApActor $actor, mixed $asked): array
    {
        $readable = [];
        foreach ($this->registry->all() as $box) {
            if ($box->canRead(Module::APP_AP)) {
                $readable[strtolower(trim($box->address))] = true;
            }
        }
        ksort($readable);
        $readable = array_map('strval', array_keys($readable));

        $requested = $readable;
        if (ApRules::truthy($asked)) {
            if (!is_array($asked) || !array_is_list($asked)) {
                throw new ApInvalid(self::BAD_MAILBOXES);
            }
            $requested = [];
            foreach ($asked as $address) {
                if (!is_string($address)) {
                    throw new ApInvalid(self::BAD_MAILBOXES);
                }
                $requested[] = strtolower(trim($address));
            }
        }
        $chosen = [];
        foreach ($requested as $address) {
            if (in_array($address, $readable, true) && !in_array($address, $chosen, true) && $actor->mailboxScope->allowsMailbox($address)) {
                $chosen[] = $address;
            }
        }
        if ($chosen === []) {
            throw new ApInvalid(self::NO_MAILBOX);
        }

        return $chosen;
    }

    /**
     * Every folder of one mailbox, newest first. A window over 31 days is read in 31-day slices, and a slice that fills the
     * listing cap is split until it fits, so the oldest mail is never silently dropped. The cap
     * (`FINANCE_AP_MAX_SCAN_MESSAGES`) then keeps the newest N.
     *
     * @return list<MailboxMessageSummary>
     */
    private function listMailbox(string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, int $actorUserId): array
    {
        $found = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $sliceEnd = min($to, $cursor->modify('+' . self::SLICE_DAYS . ' days'));
            foreach ($this->listSplitting($mailbox, $cursor, $sliceEnd, $actorUserId) as $message) {
                $found[$message->id] = $message;
            }
            $cursor = $sliceEnd->modify('+1 second');
        }
        $messages = array_values($found);
        usort($messages, static fn (MailboxMessageSummary $a, MailboxMessageSummary $b): int => $b->receivedAt <=> $a->receivedAt);

        return $this->maxScanMessages > 0 ? array_slice($messages, 0, $this->maxScanMessages) : $messages;
    }

    /**
     * @return list<MailboxMessageSummary>
     */
    private function listSplitting(string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, int $actorUserId): array
    {
        // No folder: the whole mailbox (Q6), Sent Items, Deleted Items and Junk included.
        $messages = $this->mailbox->listMessages(Module::APP_AP, $mailbox, $from, $to, $this->listCap, $actorUserId);
        $span = $to->getTimestamp() - $from->getTimestamp();
        if (count($messages) < $this->listCap || $span < 2) {
            return $messages;
        }
        $middle = $from->modify('+' . intdiv($span, 2) . ' seconds');

        return [...$this->listSplitting($mailbox, $from, $middle, $actorUserId), ...$this->listSplitting($mailbox, $middle->modify('+1 second'), $to, $actorUserId)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function priorRun(ApScanRun $run): array
    {
        return [
            'id' => $run->id,
            'fromDate' => $run->fromDate,
            'toDate' => $run->toDate,
            'scannedAt' => ApPresenter::iso($run->createdAt),
            'scannedBy' => $run->requestedBy !== '' ? $run->requestedBy : 'Unknown',
            'totalMessages' => $run->totalMessages,
            'invoiceEmails' => $run->invoiceEmails,
            'status' => $run->status,
        ];
    }

    // ==================================================================================
    // process
    // ==================================================================================

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function process(ApActor $actor, array $body): array
    {
        $messageId = ApRules::textOrEmpty($body['messageId'] ?? null);
        $mailbox = strtolower(ApRules::textOrEmpty($body['mailbox'] ?? null));
        if ($messageId === '' || $mailbox === '') {
            throw new ApInvalid(self::MESSAGE_REQUIRED);
        }
        if (!$actor->mailboxScope->allowsMailbox($mailbox)) {
            throw new ApForbidden(self::MAILBOX_FORBIDDEN);
        }
        if (mb_strlen($messageId) > ApEmail::TEXT_LIMITS['graph_message_id']) {
            // A cut id would never match the message again; refuse rather than store a key that cannot be found (R28).
            throw new ApInvalid(self::MESSAGE_TOO_LONG);
        }
        $force = ApRules::truthy($body['force'] ?? null);
        $runId = ApRules::textOrEmpty($body['scanRunId'] ?? null);
        // A run id that names no run is ignored, as the source did (and the email's run column is a foreign key).
        $run = $runId !== '' ? $this->scans->find($runId) : null;

        try {
            $message = $this->mailbox->getMessage(Module::APP_AP, $mailbox, $messageId, $actor->userId);
            $listed = $message->summary->hasAttachments
                ? $this->mailbox->listAttachments(Module::APP_AP, $mailbox, $messageId, $actor->userId)
                : [];
        } catch (FinanceMailRefused $refused) {
            throw new ApForbidden($refused->getMessage(), [], $refused);
        } catch (MailboxReadFailed | FinanceMailException $failure) {
            throw new ApUpstream('Could not fetch the message from Outlook: ' . $failure->getMessage(), ApUpstream::MAILBOX, $failure);
        }
        $summary = $message->summary;

        // The email row is saved first: what Graph says about it, and a new one as Other / Pending classification / Unverified.
        $existing = $this->emails->findByMessage($mailbox, $messageId);
        $email = $this->emails->register(new ApEmailArrival(
            $mailbox,
            $messageId,
            $summary->receivedAt,
            $summary->fromName,
            $summary->fromAddress,
            $summary->subject,
            $summary->bodyPreview,
            '',
            $summary->hasAttachments,
            $run?->id,
        ));

        // An email in the Bin was put there as not worth capturing: a re-scan leaves it alone until it is restored.
        if ($existing !== null && $existing->isBinned() && !$force) {
            if ($run !== null) {
                $this->scans->bump($run->id, ['processed_messages' => 1], $this->claimUntil(), $this->time->now());
            }

            return ['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'binned' => true, 'classification' => $existing->classification !== '' ? $existing->classification : ApEmail::OTHER];
        }

        $captured = 0;
        $errors = 0;
        $duplicates = 0;
        $detected = false;
        $supported = 0;
        $reasons = [];
        $confidences = [];
        foreach ($listed as $attachment) {
            $name = ApAttachmentRules::nameFor($attachment, $this->minAttachmentBytes);
            if ($name === null) {
                continue;
            }
            ++$supported;
            $outcome = $this->attachment($actor, $email, $summary, $attachment, $name, $force);
            $detected = $detected || $outcome['detected'];
            $captured += $outcome['captured'];
            $errors += $outcome['errors'];
            $duplicates += $outcome['duplicates'];
            if ($outcome['reason'] !== null) {
                $reasons[] = $outcome['reason'];
            }
            if ($outcome['confidence'] !== null) {
                $confidences[] = $outcome['confidence'];
            }
        }

        $classification = ApEmail::OTHER;
        if ($detected) {
            $classification = ApEmail::INVOICE;
        }
        // The weakest attachment drives the email's score: one shaky invoice among five is what a reviewer needs to see.
        $confidence = $confidences === [] ? 0.0 : min($confidences);
        if ($supported === 0) {
            $reasons[] = self::NO_SUPPORTED_FILE;
        }
        if ($force && $captured > 0) {
            // Capturing by hand is the person saying what the email is.
            $classification = ApEmail::INVOICE;
        } elseif ($existing !== null && $existing->reviewStatus === ApEmail::VERIFIED) {
            // A person's decision is kept; the invoices were still captured above.
            $classification = $existing->classification !== '' ? $existing->classification : $classification;
            $reasons = [ApRules::truthy($existing->classificationReason) ? (string) $existing->classificationReason : 'Manually verified'];
        }
        $updates = [
            'classification' => $classification,
            'classification_reason' => mb_substr(implode('; ', array_filter($reasons, static fn (string $reason): bool => $reason !== '')), 0, ApEmail::REASON_LIMIT),
            'classification_confidence' => $errors > 0 ? 0.0 : $confidence,
            // `Needs review` is sticky: a clean re-scan keeps it until a person verifies (R18).
            'review_status' => $errors > 0 ? ApEmail::NEEDS_REVIEW : ($existing?->reviewStatus ?? ApEmail::UNVERIFIED),
        ];
        // A first-draft reply for the composer, once: a suggestion already there (reviewed, edited) is never overwritten.
        // Null is "switched off": nothing is stored and no model is called.
        if (!ApRules::truthy($email->proposedResponse)) {
            $suggestion = $this->ai->suggestReply(new ApDraftEmail($email->senderName, $email->senderEmail, $email->subject, $email->bodyPreview));
            if ($suggestion !== null) {
                $updates['proposed_response'] = $suggestion;
            }
        }
        $this->emails->applyScanResult($email->id, $updates);

        if ($run !== null) {
            $this->scans->bump($run->id, [
                'processed_messages' => 1,
                'invoice_emails' => $classification === ApEmail::INVOICE ? 1 : 0,
                'other_emails' => $classification === ApEmail::OTHER ? 1 : 0,
                'duplicate_invoices' => $duplicates,
                'errors' => $errors,
            ], $this->claimUntil(), $this->time->now());
        }

        return ['captured' => $captured, 'errors' => $errors, 'duplicates' => $duplicates, 'classification' => $classification];
    }

    /**
     * One selected attachment (steps c to m of the pipeline). Never throws for the file itself: a failure is counted.
     *
     * @return array{detected: bool, captured: int, errors: int, duplicates: int, reason: ?string, confidence: ?float}
     */
    private function attachment(ApActor $actor, ApEmail $email, MailboxMessageSummary $message, MailboxAttachmentSummary $attachment, string $name, bool $force): array
    {
        $none = ['detected' => false, 'captured' => 0, 'errors' => 0, 'duplicates' => 0, 'reason' => null, 'confidence' => null];
        $messageId = $email->graphMessageId;
        $attachmentId = $attachment->id !== '' ? $attachment->id : null;
        $key = $attachmentId ?? $name;

        // Already captured, live or in the Bin: a binned capture is never re-captured.
        $existing = $this->invoiceStore->findCaptured($messageId, $attachmentId, $name);
        if ($existing !== null) {
            $this->recordFile($email, $key, $name, $attachment, ['is_invoice' => true, 'invoice_id' => $existing->id]);

            return ['detected' => true, 'reason' => 'Previously captured invoice: ' . $name] + $none;
        }
        // A capture deleted for good does not come back.
        if ($this->invoiceStore->scanSkipped($messageId, $attachmentId, $name)) {
            return $none;
        }

        $fileId = null;
        try {
            $bytes = $this->mailbox->getAttachment(Module::APP_AP, $email->mailbox, $messageId, $attachment->id, $actor->userId)->content;
            if ($bytes === '') {
                throw new RuntimeException('attachment has no content');
            }
            $hash = hash('sha256', $bytes);

            // Rule 1, before any model call: an exact copy of an invoice already filed (live or binned) files nothing.
            $exact = $this->invoices->findExactDuplicate($hash);
            if ($exact !== null) {
                $this->recordFile($email, $key, $name, $attachment, ['content_hash' => $hash, 'is_invoice' => true, 'duplicate_of_invoice_id' => $exact->id]);

                return ['detected' => true, 'duplicates' => 1, 'reason' => 'Exact duplicate attachment: ' . $name] + $none;
            }

            $contentType = ApAttachmentRules::contentTypeOf($name);
            $fileId = $this->attachments->keep($name, $contentType, $bytes, $actor->userId)->id;
            $verdict = $this->ai->classify($message->subject, $message->fromAddress, $name, $contentType, $bytes, $this->companies());
            $forced = $force && !$verdict->isInvoice;

            if (!$verdict->isInvoice && !$force) {
                // Nothing marks this file as known: the next scan sends it to the model again (R17, kept as the source did).
                $this->recordFile($email, $key, $name, $attachment, ['content_hash' => $hash, 'is_invoice' => false]);
                $this->discardQuietly($fileId);

                return ['reason' => $verdict->reason ?? 'Not an invoice: ' . $name] + $none;
            }

            $invoice = $this->emailStore->transactional(function () use ($actor, $email, $message, $attachmentId, $key, $name, $attachment, $verdict, $forced, $fileId, $hash): ApInvoice {
                $invoice = $this->invoices->createFromScan(new ApInvoiceCapture(
                    $email->mailbox,
                    $email->graphMessageId,
                    $email->id,
                    $attachmentId,
                    $message->receivedAt,
                    $message->fromAddress,
                    $message->subject,
                    $name,
                    $verdict->extraction,
                    $verdict->status($forced),
                    $verdict->reviewReason($forced),
                    ApExtraction::resolveVendorEmail($verdict->extraction->vendorEmail, $message->fromAddress, $this->internalDomainsFor($email->mailbox)),
                    $fileId,
                    $hash,
                ), $actor->userId);
                $this->recordFile($email, $key, $name, $attachment, [
                    'content_hash' => $hash,
                    'file_id' => $fileId,
                    'is_invoice' => true,
                    'invoice_id' => $invoice->id,
                    'duplicate_of_invoice_id' => $invoice->duplicateOfInvoiceId,
                ]);

                return $invoice;
            });
            $fileId = null;

            return [
                'detected' => true,
                'captured' => 1,
                'duplicates' => $invoice->duplicateStatus === ApInvoice::DUPLICATE_POTENTIAL ? 1 : 0,
                'reason' => $verdict->reason ?? 'Invoice attachment: ' . $name,
                'confidence' => $verdict->extraction->extractionConfidence,
            ] + $none;
        } catch (ApConflict) {
            // Another request filed this very file between the check and the write: it is there, which is all a scan needs.
            $this->discardQuietly($fileId);

            return $none;
        } catch (Throwable $failure) {
            ($this->log)(sprintf('Failed to process attachment on a message (%s): %s', $failure::class, self::safe($failure)));
            $this->discardQuietly($fileId);

            // No file row and no tombstone is written, so the next scan retries the file.
            return ['errors' => 1, 'reason' => 'Could not classify ' . $name . '; manual verification required'] + $none;
        }
    }

    /**
     * Writes the file row (`upsert_attachment`): a new row, or the existing one for this Graph attachment id with the facts
     * given here laid over it. A fact not given keeps what the row had, as the source's update of only the keys it sent did.
     *
     * @param array<string, mixed> $facts any of `content_hash`, `file_id`, `is_invoice`, `invoice_id`, `duplicate_of_invoice_id`
     */
    private function recordFile(ApEmail $email, string $key, string $name, MailboxAttachmentSummary $attachment, array $facts): void
    {
        $kept = ['content_hash' => null, 'file_id' => null, 'is_invoice' => false, 'invoice_id' => null, 'duplicate_of_invoice_id' => null];
        foreach ($this->emails->attachmentsOf($email->id) as $row) {
            if ($row->graphAttachmentId !== null && strcasecmp($row->graphAttachmentId, $key) === 0) {
                $kept = [
                    'content_hash' => $row->contentHash,
                    'file_id' => $row->fileId,
                    'is_invoice' => $row->isInvoice,
                    'invoice_id' => $row->invoiceId,
                    'duplicate_of_invoice_id' => $row->duplicateOfInvoiceId,
                ];
                break;
            }
        }
        $row = $facts + $kept;
        $this->emails->recordAttachment(
            $email->id,
            $key,
            $name,
            $attachment->contentType,
            $attachment->size,
            $row['content_hash'],
            $row['file_id'],
            (bool) $row['is_invoice'],
            $row['invoice_id'],
            $row['duplicate_of_invoice_id'],
        );
    }

    // ==================================================================================
    // complete
    // ==================================================================================

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function complete(array $body): array
    {
        $id = ApRules::textOrEmpty($body['scanRunId'] ?? null);
        $run = $id !== '' ? $this->scans->find($id) : null;
        if ($run === null) {
            throw new ApNotFound(self::RUN_NOT_FOUND);
        }
        // Complete from any state, a Failed run included, as the source's update did.
        $this->scans->finish($run->id, ApScanRun::COMPLETE, $this->time->now());

        return self::present($this->scans->find($run->id) ?? $run);
    }

    /**
     * A run as the source's `scan_runs` row.
     *
     * @return array<string, mixed>
     */
    public static function present(ApScanRun $run): array
    {
        return [
            'id' => $run->id,
            'requested_by' => $run->requestedBy,
            'from_date' => $run->fromDate,
            'to_date' => $run->toDate,
            'status' => $run->status,
            'total_messages' => $run->totalMessages,
            'processed_messages' => $run->processedMessages,
            'invoice_emails' => $run->invoiceEmails,
            'other_emails' => $run->otherEmails,
            'duplicate_invoices' => $run->duplicateInvoices,
            'errors' => $run->errors,
            'created_date' => ApPresenter::iso($run->createdAt),
            'updated_date' => ApPresenter::iso($run->updatedAt),
        ];
    }

    // ==================================================================================
    // helpers
    // ==================================================================================

    private function claimUntil(): DateTimeImmutable
    {
        return $this->time->now()->modify('+' . self::CLAIM_MINUTES . ' minutes');
    }

    /**
     * Our own mail domains: the setting, else the domain of the mailbox being read (the source's default).
     *
     * @return list<string>
     */
    private function internalDomainsFor(string $mailbox): array
    {
        if ($this->internalDomains !== []) {
            return $this->internalDomains;
        }
        $at = strrpos($mailbox, '@');

        return $at === false ? [] : [substr($mailbox, $at + 1)];
    }

    /**
     * The newest 200 companies (`_active_company_names()`), inactive ones included: the prompt names the active ones and the
     * answer is matched against them.
     *
     * @return list<ApExtractionCompany>
     */
    private function companies(): array
    {
        $companies = [];
        foreach ($this->references->rows(ApReferenceSpec::for(ApReferenceSpec::COMPANIES), 'created_date', true, 200) as $row) {
            $companies[] = new ApExtractionCompany(
                $row->values['name'] ?? '',
                ($row->values['code'] ?? '') !== '' ? $row->values['code'] : null,
                $row->values['sites'] ?? '',
                $row->values['former_names'] ?? '',
                $row->active,
            );
        }

        return $companies;
    }

    /** Takes back a kept file that went on no invoice. A failure is logged and goes no further: the daily sweep removes strays. */
    private function discardQuietly(?string $fileId): void
    {
        if ($fileId === null) {
            return;
        }
        try {
            $this->attachments->discard($fileId);
        } catch (Throwable $failure) {
            ($this->log)(sprintf('Tri-AP scan: a kept file could not be removed (%s)', $failure::class));
        }
    }

    private static function safe(Throwable $failure): string
    {
        return $failure instanceof ApFailure || $failure instanceof MailboxReadFailed || $failure instanceof FinanceMailException || $failure instanceof \TriCab\Core\Finance\Shared\Ai\FinanceAiFailure
            ? mb_substr($failure->getMessage(), 0, 300)
            : '';
    }
}
