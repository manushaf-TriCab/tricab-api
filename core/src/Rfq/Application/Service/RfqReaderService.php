<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use DateTimeImmutable;
use Throwable;
use TriCab\Core\Rfq\Application\DTO\ReaderResult;
use TriCab\Core\Rfq\Application\DTO\SourceDocument;
use TriCab\Core\Rfq\Application\DTO\UploadedRfq;
use TriCab\Core\Rfq\Contract\RfqCorrectionMemory;
use TriCab\Core\Rfq\Contract\RfqCustomerDirectory;
use TriCab\Core\Rfq\Contract\RfqDocumentStore;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Contract\RfqReaderJobStore;
use TriCab\Core\Rfq\Contract\RfqReaderWake;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Service\ReaderJobTransitionPolicy;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;
use TriCab\Core\Rfq\Domain\ValueObject\RfqAction;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ReadRequest;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\DraftChangeLog;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\SalesRepAttribution;
use TriCab\Core\Rfq\Storage\StoredReaderJob;
use TriCab\Core\Rfq\Storage\StoreFormat;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Shared\Time\Clock;

/**
 * File intake and the background reader (plans 2.5, 3.3, 3.5): the source's
 * portal upload (`rfq_handle_upload`), its reader worker
 * (`reader_jobs.run_reader_jobs`) and `resolve_reader_job`, with the job and
 * the documents held by PHP.
 *
 * ## Upload
 *
 * Every file is read deterministically at once, inside the parser's bounds,
 * and the rows become a draft, as the source's upload does. Each file's bytes
 * go to the private document store, and each one a model reader will do
 * better on (`readerRoute`) is queued for a read. Storing or queueing never
 * fails the upload: the rows are there, and the draft says the read is not
 * coming.
 *
 * ## The worker
 *
 * One draft at a time, every queued read of it together (a three-file upload
 * applied one file at a time would make the first replacement look like the
 * rep's edit to the second). When nobody has touched the draft since the
 * upload, each file's rows are replaced wholesale; otherwise they become a
 * proposal the rep accepts or dismisses. A retryable failure (the provider is
 * down or throttling, the parser timed out) goes back in the queue until the
 * job has had {@see ReaderJobTransitionPolicy::MAX_ATTEMPTS}; anything else
 * fails loudly, on the draft, in the rep's words.
 */
final class RfqReaderService
{
    public function __construct(
        private readonly RfqParserClient $parser,
        private readonly RfqDraftStore $drafts,
        private readonly RfqReaderJobStore $jobs,
        private readonly RfqDocumentStore $documents,
        private readonly RfqRepository $rfqs,
        private readonly RfqOperatorDirectory $operators,
        private readonly Clock $clock,
        private readonly bool $allowModel = false,
        private readonly ?RfqCorrectionMemory $memory = null,
        private readonly ?RfqCustomerDirectory $customers = null,
        private readonly ?RfqReaderWake $wake = null,
    ) {
    }

    /**
     * @param list<array{fileName: string, contentType: string, bytes: string}> $files
     *
     * @throws InvalidRfqValue    for no files, too many, an oversized one, or nothing readable
     * @throws RfqActionForbidden without `RFQ.DRAFT.CREATE`
     * @throws RfqParserFailure   when the parser refuses the files or cannot answer
     */
    public function upload(RfqActor $actor, string $email, array $files, CorrelationId $id): UploadedRfq
    {
        if (!$actor->can(Module::PERMISSION_DRAFT_CREATE)) {
            throw new RfqActionForbidden('create RFQs');
        }
        $documents = [];
        foreach ($files as $index => $file) {
            $documents[] = [
                'documentPublicId' => 'upload-' . ($index + 1),
                'fileName' => self::displayName($file['fileName']),
                'contentType' => $file['contentType'],
                'bytes' => $file['bytes'],
            ];
        }

        return $this->intake($actor, $email, $documents, $id, 'web', [], null, null, false);
    }

    /**
     * RFQ mail intake (mail plan M6.1): the draft an email becomes, through the same intake as an upload
     * (the same parse, ownership, document store and background reader), as source `email`. The email's
     * body (as text) and its readable attachments are the documents; `$emailMeta` (the message ids, the
     * original sender) is kept on the draft beside the owner and the parse accounting.
     *
     * **Idempotent on `$draftId`**, which the caller derives from the message's internetMessageId. If a draft
     * with that id exists, it is returned and nothing is parsed or written (apart from keeping the files if
     * the first run died before storing them). If two runs race past that check, the second insert collides
     * on the primary key and that run returns the winner's draft: one message can never make two drafts.
     * An email the parser reads nothing from still becomes a draft with no lines, with the email's files
     * kept, so that a person sees it; an upload with nothing readable is refused instead.
     *
     * With `$envelope` (`rfq-mail-envelope.v1`, as {@see ParseRequest::forEmail()} takes it) the parser reads the
     * whole email in one pass: body, forwarded chain and attachments, merged. `$files` is then what is kept on the
     * draft: the body first, then the envelope's `documents` in order, so that `email-N` (N = position + 1) names
     * the same file in both. Without it every file is parsed as an upload.
     *
     * @param list<array{fileName: string, contentType: string, bytes: string}> $files
     * @param array<string, mixed> $emailMeta
     * @param array<string, mixed>|null $envelope
     *
     * @return array{publicId: string, created: bool, lineCount: int}
     *
     * @throws InvalidRfqValue    for no files or an oversized one
     * @throws RfqActionForbidden without `RFQ.DRAFT.CREATE`
     * @throws RfqParserFailure   when the parser refuses the files or cannot answer (nothing was created)
     */
    public function createFromEmail(RfqActor $actor, string $email, string $draftId, string $label, array $files, array $emailMeta, CorrelationId $id, ?array $envelope = null): array
    {
        if (!$actor->can(Module::PERMISSION_DRAFT_CREATE)) {
            throw new RfqActionForbidden('create RFQs');
        }
        if (!StoreFormat::isId($draftId)) {
            throw new InvalidRfqValue('The draft id must be 32 lower-case hex characters.');
        }
        $documents = [];
        foreach ($files as $index => $file) {
            $documents[] = [
                'documentPublicId' => 'email-' . ($index + 1),
                'fileName' => self::displayName($file['fileName']),
                'contentType' => $file['contentType'],
                'bytes' => $file['bytes'],
            ];
        }

        $existing = $this->drafts->load($draftId);
        if ($existing === null) {
            try {
                $made = $this->intake($actor, $email, $documents, $id, 'email', $emailMeta, $label, $draftId, true, $envelope);

                return ['publicId' => $made->publicId, 'created' => true, 'lineCount' => $made->lineCount];
            } catch (Throwable $failure) {
                // A refused parse created nothing, so there is no draft and the failure is rethrown. But a
                // second run may have got in between the check and the insert (or this one died after the
                // insert committed): if the draft is there now, it is the one.
                $existing = $this->drafts->load($draftId);
                if ($existing === null) {
                    throw $failure;
                }
            }
        }
        // Someone else's draft for this very message: keep it, make sure its files were kept.
        try {
            if ($this->documents->forDraft($draftId) === []) {
                foreach ($documents as $document) {
                    $this->documents->store($draftId, $document['fileName'], $document['contentType'], $document['bytes'], $email);
                }
            }
        } catch (Throwable) {
            error_log(sprintf('RFQ email documents not stored for existing draft (correlation %s)', $id->toString()));
        }

        return ['publicId' => StoreProjection::publicId($draftId), 'created' => false, 'lineCount' => count($existing->rows)];
    }

    /** Whether this follow-up already has its proposal (or empty marker) on the draft, so nothing need be fetched. */
    public function hasEmailProposal(string $draftId, string $jobId): bool
    {
        return StoreFormat::isId($draftId) && StoreFormat::isId($jobId) && $this->jobs->find($draftId, $jobId) !== null;
    }

    /**
     * An email follow-up on an open draft becomes a proposal (RFQ mail M6.2): the envelope is parsed the way the
     * first email was, and the rows are recorded as a finished read holding a `proposal`, which the editor offers
     * with Accept / Dismiss like any re-read that arrives after the rep has edited. Nothing is applied, whatever
     * the draft's state, and there is no second draft.
     *
     * Every row's `source.file_name` becomes this proposal's own name, so accepting appends the rows beside the
     * rest and can never splice over the rows of another file (the email body, say). The file the parser read is
     * kept as `source.email_file`.
     *
     * **Idempotent per `$jobId`** (the caller derives it from the message): a second call parses nothing and records
     * nothing. A draft that is gone or archived is left alone.
     *
     * @param array<string, mixed> $envelope the `rfq-mail-envelope.v1`
     * @param array{sender_name: string, sender_address: string, received_at: string, subject: string, mail_message_id: string, mail_thread_id: string, internet_message_id: string} $email what the editor shows about the message
     *
     * @return 'proposed'|'empty'|'exists'|'no_draft'
     *
     * @throws RfqParserFailure when the parser cannot answer (nothing was recorded; the caller may retry)
     */
    public function proposeFromEmail(string $draftId, string $jobId, string $uploaderEmail, array $envelope, array $email, CorrelationId $id): string
    {
        if (!StoreFormat::isId($draftId) || !StoreFormat::isId($jobId)) {
            throw new InvalidRfqValue('The draft and proposal ids must be 32 lower-case hex characters.');
        }
        $draft = $this->drafts->load($draftId);
        if ($draft === null || DraftChangeLog::truthy($draft->columns['archived_at'] ?? null)) {
            return 'no_draft';
        }
        if ($this->jobs->find($draftId, $jobId) !== null) {
            return 'exists';
        }

        $parsed = $this->parser->parse(ParseRequest::forEmail($id, $envelope, $this->allowModel)->withCorrections($this->corrections()));
        $when = StoreFormat::parseTime($email['received_at']);
        $who = trim($email['sender_name']) !== '' ? trim($email['sender_name']) : trim($email['sender_address']);
        $fileName = sprintf('Email follow-up from %s, %s [%s]', $who !== '' ? $who : 'unknown sender', ($when ?? $this->clock->now())->format('j M Y H:i'), substr($jobId, 0, 4));
        $rows = [];
        foreach ($parsed->lines as $line) {
            $source = is_array($line['source'] ?? null) ? $line['source'] : [];
            if (is_string($source['file_name'] ?? null) && $source['file_name'] !== '') {
                $source['email_file'] = $source['file_name'];
            }
            $source['file_name'] = $fileName;
            $line['source'] = $source;
            $line['line_uid'] = StoreFormat::newId();
            $rows[] = $line;
        }
        $messageKey = $email['mail_message_id'];
        if ($rows === []) {
            $this->jobs->recordEmailProposal($draftId, $jobId, $fileName, $messageKey, $uploaderEmail, $draft->revision, 'The follow-up email held no lines to propose.', null);
            $this->jobs->recordEvent($draftId, 'email_followup_no_lines', "{$who} sent a follow-up email with no lines in it", ['mail_message_id' => $messageKey]);

            return 'empty';
        }
        $recorded = $this->jobs->recordEmailProposal($draftId, $jobId, $fileName, $messageKey, $uploaderEmail, $draft->revision, 'Parsed from an email follow-up.', [
            'reader' => 'email follow-up',
            'file_name' => $fileName,
            'rows' => $rows,
            'notes' => $parsed->notes,
            'base_revision' => $draft->revision,
            'email' => $email,
        ]);
        if (!$recorded) {
            return 'exists';
        }
        $this->jobs->recordEvent($draftId, 'email_followup_proposal', sprintf('%s sent a follow-up email; its %d line(s) are a proposal', $who, count($rows)), [
            'mail_message_id' => $messageKey,
            'mail_thread_id' => $email['mail_thread_id'],
            'lines' => count($rows),
        ]);
        $this->jobs->refreshStatus($draftId, false);

        return 'proposed';
    }

    /**
     * The shared intake: every document is read at once and the rows become a draft; each file a model
     * reader will do better on is queued.
     *
     * @param list<array{documentPublicId: string, fileName: string, contentType: string, bytes: string}> $documents
     * @param array<string, mixed> $extraMeta recorded with the draft
     * @param array<string, mixed>|null $envelope
     *
     * @throws InvalidRfqValue when nothing was readable (unless `$allowEmpty`)
     */
    private function intake(RfqActor $actor, string $email, array $documents, CorrelationId $id, string $source, array $extraMeta, ?string $labelOverride, ?string $draftId, bool $allowEmpty, ?array $envelope = null): UploadedRfq
    {
        $request = $envelope !== null
            ? ParseRequest::forEmail($id, $envelope, $this->allowModel)
            : ParseRequest::forDocuments($id, $documents, $this->allowModel);
        $parsed = $this->parser->parse($request->withCorrections($this->corrections()));
        $routes = [];
        foreach ($parsed->documents as $summary) {
            $routes[$summary['documentPublicId']] = $summary['readerRoute'];
        }
        $queueable = array_filter($documents, static fn (array $d): bool => $routes[$d['documentPublicId']] ?? false);
        if ($parsed->lines === [] && $queueable === [] && !$allowEmpty) {
            throw InvalidRfqValue::nothingRead('files', $parsed->notes);
        }

        $quotes = [];
        foreach ($parsed->documents as $summary) {
            if (isset($summary['quoteImport'])) {
                $quotes[] = $summary['quoteImport'];
            }
        }
        // Only an upload made entirely of recognised quotes is named after them, and only a
        // single quote gives the draft its header (the source's `upload_draft_identity`).
        $allQuotes = $quotes !== [] && count($quotes) === count($documents);

        $rows = array_map(static function (array $row): array {
            $row['line_uid'] = StoreFormat::newId();

            return $row;
        }, $parsed->lines);
        $worker = $this->draftActor($actor, $email);
        $rep = SalesRepAttribution::apply([], null, $worker, $worker->operatorCode);
        $meta = [
            'owner_email' => $worker->email,
            'rep_email' => $worker->email,
            'parse' => [
                'correlation_id' => $parsed->correlationId,
                'engine' => $parsed->engine,
                'provider' => $parsed->provider,
                'coverage' => $parsed->coverage,
                'degraded' => $parsed->degraded,
                'documents' => $parsed->documents,
            ],
        ];
        if ($parsed->email !== []) {
            // What the parser learned about the message (original sender, forwarded parts, template labels,
            // attachments left out), kept beside the accounting.
            $meta['parse']['email'] = $parsed->email;
        }
        $login = SalesRepAttribution::login($email);
        if ($login !== '') {
            $meta['owner_login'] = $login;
        }
        if ($worker->branch !== null) {
            $meta['owner_branch'] = $worker->branch;
        }
        if ($rep['authorSource'] !== null) {
            $meta['author_source'] = $rep['authorSource'];
        }
        $meta += $extraMeta;
        if ($quotes !== []) {
            $meta['quote_imports'] = array_map(static fn (array $q): array => [
                'template' => is_string($q['template'] ?? null) ? $q['template'] : '',
                'quote_number' => is_string($q['quote_number'] ?? null) ? $q['quote_number'] : '',
                'status' => is_string($q['status'] ?? null) ? $q['status'] : '',
                'label' => is_string($q['label'] ?? null) ? $q['label'] : '',
            ], $quotes);
        }
        $quoteDetails = $rep['quoteDetails'];
        if ($allQuotes && count($quotes) === 1 && is_array($quotes[0]['quote_details'] ?? null)) {
            $quoteDetails = $this->importedHeader($quoteDetails, $quotes[0]['quote_details']);
        }
        $label = $allQuotes ? self::quoteLabel($quotes) : '';
        $draftId = $this->drafts->create(new NewDraft(
            source: $source,
            label: $labelOverride ?? ($label !== '' ? $label : self::label(array_column($documents, 'fileName'), $this->clock->now())),
            quoteDetails: $quoteDetails,
            rows: $rows,
            emailMeta: $meta,
            creatorEmail: $email,
            branch: $worker->branch ?? '',
            id: $draftId,
        ));

        $reads = [];
        $errors = [];
        foreach ($documents as $document) {
            $queued = false;
            try {
                $stored = $this->documents->store($draftId, $document['fileName'], $document['contentType'], $document['bytes'], $email);
                if ($routes[$document['documentPublicId']] ?? false) {
                    $documentId = StoreProjection::draftId($stored->publicId) ?? '';
                    $this->jobs->enqueue($draftId, $documentId, $document['fileName'], $document['contentType'], $email, 1);
                    $queued = true;
                }
            } catch (Throwable $failure) {
                error_log(sprintf('RFQ document not stored (%s, correlation %s)', $failure::class, $id->toString()));
                $errors[] = $document['fileName'];
            }
            $reads[] = ['fileName' => $document['fileName'], 'queued' => $queued];
        }
        if (in_array(true, array_column($reads, 'queued'), true)) {
            $this->wake?->signal();
        }
        if ($errors !== []) {
            $this->jobs->recordEvent(
                $draftId,
                'reader_queue_failed',
                'These files could not be kept for re-reading: ' . implode(', ', $errors),
                ['files' => $errors],
            );
        }

        return new UploadedRfq(
            StoreProjection::publicId($draftId),
            count($rows),
            $parsed->coverage,
            $parsed->isCoverageComplete(),
            $parsed->degraded,
            $parsed->notes,
            $reads,
        );
    }

    /**
     * One pass of the reader worker: recover stale claims, then read up to
     * `$maxDrafts` drafts' queued documents. `outstanding` counts the reads
     * still unfinished afterwards; while it is above zero the worker keeps
     * checking every pass, and at zero it waits for {@see RfqReaderWake}.
     *
     * @return array{recovered: int, drafts: int, jobs: int, applied: int, proposed: int, failed: int, retried: int, superseded: int, outstanding: int}
     */
    public function runWorker(int $maxDrafts = 1): array
    {
        $before = StoreFormat::isoTime($this->clock->now()->modify('-' . ReaderJobTransitionPolicy::STALE_AFTER_MINUTES . ' minutes'));
        $summary = [
            'recovered' => $this->jobs->requeueStale($before, ReaderJobTransitionPolicy::MAX_ATTEMPTS),
            'drafts' => 0, 'jobs' => 0, 'applied' => 0, 'proposed' => 0, 'failed' => 0, 'retried' => 0, 'superseded' => 0,
        ];
        for ($i = 0; $i < max(1, $maxDrafts); $i++) {
            $draftId = $this->jobs->nextDraft();
            if ($draftId === null) {
                break;
            }
            $claimed = $this->jobs->claim($draftId);
            if ($claimed === []) {
                continue;
            }
            $summary['drafts']++;
            $this->readDraft($draftId, $claimed, $summary);
        }

        return $summary + ['outstanding' => $this->jobs->outstanding()];
    }

    /**
     * @param list<StoredReaderJob>                                                                                               $claimed
     * @param array{recovered: int, drafts: int, jobs: int, applied: int, proposed: int, failed: int, retried: int, superseded: int} $summary
     */
    private function readDraft(string $draftId, array $claimed, array &$summary): void
    {
        $label = $this->drafts->load($draftId)?->columns['label'] ?? null;
        $subject = is_string($label) ? $label : '';
        $attachments = array_values(array_unique(array_map(static fn (StoredReaderJob $j): string => $j->fileName, $this->jobs->forDraft($draftId))));
        $baseRevision = null;
        $rowsByFile = [];
        $notes = [];
        $readers = [];
        $read = [];
        foreach ($claimed as $job) {
            $summary['jobs']++;
            $baseRevision ??= $job->baseRevision;
            $id = CorrelationId::generate();
            $bytes = $job->documentId === null ? null : $this->documents->bytes($draftId, $job->documentId);
            if ($bytes === null || $bytes === '') {
                $this->fail($draftId, $job, 'the uploaded file was no longer stored to re-read');
                $summary['failed']++;
                continue;
            }
            try {
                $answer = $this->parser->read(ReadRequest::forDocument(
                    $id,
                    StoreProjection::publicId($job->documentId ?? ''),
                    $job->fileName,
                    $job->contentType ?? '',
                    $bytes,
                    $subject,
                    $attachments,
                )->withCorrections($this->corrections()));
            } catch (RfqParserFailure $failure) {
                $summary[$this->handleFailure($draftId, $job, $failure)]++;
                continue;
            } catch (InvalidRfqValue $invalid) {
                $this->fail($draftId, $job, $invalid->getMessage());
                $summary['failed']++;
                continue;
            }
            $rowsByFile[$job->fileName] = $answer->lines;
            $notes[$job->id] = implode('; ', $answer->notes) !== '' ? implode('; ', $answer->notes) : 'Read by ' . $answer->readerName();
            $readers[] = $answer->readerName();
            $read[] = $job;
        }
        if ($read === []) {
            return;
        }
        $reader = implode(', ', array_values(array_unique($readers)));

        if ($this->jobs->isUntouched($draftId, $baseRevision)) {
            try {
                $revision = $this->jobs->applyRows($draftId, $rowsByFile, $baseRevision, $reader, "Rows re-read by {$reader}", $notes);
            } catch (RfqRevisionConflict|RfqDraftNotEditable) {
                // The rep saved between the test and the write: their work wins.
                $revision = false;
            }
            if ($revision === null) {
                foreach ($read as $job) {
                    $this->jobs->finish($job->id, 'superseded', 'The draft was deleted before the read finished.');
                    $summary['superseded']++;
                }

                return;
            }
            if ($revision !== false) {
                $summary['applied'] += count($read);

                return;
            }
        }

        foreach ($read as $job) {
            $this->jobs->finish($job->id, 'done', $notes[$job->id], [
                'reader' => $reader,
                'file_name' => $job->fileName,
                'rows' => $rowsByFile[$job->fileName],
                'notes' => [$notes[$job->id]],
                'base_revision' => $baseRevision,
            ]);
            $summary['proposed']++;
        }
        $files = array_keys($rowsByFile);
        sort($files);
        $this->jobs->recordEvent(
            $draftId,
            'reader_proposal',
            "{$reader} re-read this upload; the rows are a proposal because the draft has been edited",
            ['reader' => $reader, 'files' => $files],
        );
        $this->jobs->refreshStatus($draftId, false);
    }

    /** @return 'failed'|'retried'|'superseded' */
    private function handleFailure(string $draftId, StoredReaderJob $job, RfqParserFailure $failure): string
    {
        error_log(sprintf('RFQ read failed: %s (job %s, correlation %s)', $failure->failureCode, $job->id, $failure->correlationId ?? '-'));
        if ($failure->failureCode === RfqParserFailure::UNSUPPORTED_FORMAT) {
            // No model reader for this file any more: the rows on the draft are
            // already the deterministic parse, and must not be recorded as a read.
            $this->jobs->finish($job->id, 'superseded', 'No model reader is switched on for this file type, so it was not re-read.');

            return 'superseded';
        }
        if ($failure->isRetryable() && $job->attempts < ReaderJobTransitionPolicy::MAX_ATTEMPTS) {
            $this->jobs->finish($job->id, 'queued', 'Waiting to retry: ' . $failure->getMessage());

            return 'retried';
        }
        $this->fail($draftId, $job, $failure->getMessage());

        return 'failed';
    }

    /** The source's `_fail`: loud, on the draft, in the rep's words. */
    private function fail(string $draftId, StoredReaderJob $job, string $reason): void
    {
        $message = 'Terra could not read this file: ' . $reason;
        $this->jobs->finish($job->id, 'failed', $message);
        $this->jobs->recordEvent($draftId, 'reader_read_failed', $message, [
            'file_name' => $job->fileName,
            'reason' => $reason,
            'attempts' => $job->attempts,
        ]);
        // A failed read blocks the sync, so the badge must stop saying ready.
        $this->jobs->refreshStatus($draftId, false);
    }

    /**
     * Every read of an RFQ, for the detail. Anyone who can read it sees them.
     *
     * @return list<ReaderResult>|null null when there is no such RFQ for this actor
     */
    public function reads(RfqActor $actor, string $publicId): ?array
    {
        $draftId = $this->visibleDraft($actor, $publicId);

        return $draftId === null ? null : array_map(ReaderResult::fromStored(...), $this->jobs->forDraft($draftId));
    }

    /**
     * @return list<SourceDocument>|null
     */
    public function documents(RfqActor $actor, string $publicId): ?array
    {
        $draftId = $this->visibleDraft($actor, $publicId);

        return $draftId === null ? null : $this->documents->forDraft($draftId);
    }

    /**
     * One document's bytes and metadata, for an authorized download.
     *
     * @return array{document: SourceDocument, bytes: string}|null
     */
    public function download(RfqActor $actor, string $publicId, string $documentPublicId): ?array
    {
        $draftId = $this->visibleDraft($actor, $publicId);
        $documentId = StoreProjection::draftId($documentPublicId);
        if ($draftId === null || $documentId === null) {
            return null;
        }
        $document = $this->documents->find($draftId, $documentId);
        $bytes = $document === null ? null : $this->documents->bytes($draftId, $documentId);

        return $document === null || $bytes === null ? null : ['document' => $document, 'bytes' => $bytes];
    }

    /**
     * A person's decision on a read: accept a proposal's rows, or dismiss a
     * proposal or a failure (`resolve_reader_job`).
     *
     * @return int|null the draft's revision afterwards; null when there is no such RFQ or read
     *
     * @throws InvalidRfqValue|RfqActionForbidden|RfqRevisionConflict|RfqDraftNotEditable
     */
    public function resolve(RfqActor $actor, string $email, string $publicId, string $readPublicId, string $action, ?int $expectedRevision, CorrelationId $id): ?int
    {
        if (!in_array($action, ['accept', 'dismiss'], true)) {
            throw InvalidRfqValue::forOneOf('action', $action, ['accept', 'dismiss']);
        }
        $record = $this->rfqs->findRecord(strtolower(trim($publicId)));
        if ($record === null || !RfqAccessPolicy::canSee($actor, $record->ownership)) {
            return null;
        }
        if (!in_array(RfqAction::EDIT, RfqAccessPolicy::allowedActions($actor, $record->ownership), true)) {
            throw new RfqActionForbidden('resolve reads on this RFQ');
        }
        $draftId = StoreProjection::draftId($publicId);
        $jobId = StoreProjection::draftId($readPublicId);
        $job = $draftId === null || $jobId === null ? null : $this->jobs->find($draftId, $jobId);
        if ($draftId === null || $job === null) {
            return null;
        }
        $status = $job->readerStatus();
        if ($status->isInFlight()) {
            throw new InvalidRfqValue('This document is still being read. Wait for it to finish.');
        }
        if (!$status->needsAttention()) {
            throw new InvalidRfqValue('This read has nothing outstanding to resolve.');
        }
        $worker = $this->draftActor($actor, $email);
        $revision = null;
        if ($action === 'accept') {
            if ($job->proposal === null) {
                throw new InvalidRfqValue('This read failed, so there are no lines to accept. Dismiss it and add the lines by hand.');
            }
            $rows = is_array($job->proposal['rows'] ?? null) ? array_values(array_filter($job->proposal['rows'], 'is_array')) : [];
            $reader = is_string($job->proposal['reader'] ?? null) ? $job->proposal['reader'] : 'the reader';
            /** @var list<array<string, mixed>> $rows */
            $revision = $this->jobs->applyRows($draftId, [$job->fileName => $rows], $expectedRevision, $reader, 'Proposal accepted', [], $worker);
            if ($revision === null) {
                return null;
            }
        }
        $this->jobs->close($draftId, $job->id, $action === 'accept', $status->toString() === ReaderJobStatus::FAILED, $job->fileName, $worker);
        $this->refreshReadiness($draftId, $id);

        return $revision ?? $this->drafts->load($draftId)?->revision;
    }

    /** The source's `refresh_ready_status` after a read settles. */
    private function refreshReadiness(string $draftId, CorrelationId $id): void
    {
        foreach ($this->jobs->forDraft($draftId) as $job) {
            $status = $job->readerStatus();
            if ($status->isInFlight() || $status->needsAttention()) {
                $this->jobs->refreshStatus($draftId, false);

                return;
            }
        }
        $draft = $this->drafts->load($draftId);
        if ($draft === null) {
            return;
        }
        try {
            $rows = array_map(static function (array $row): array {
                unset($row['_draft_row_id'], $row['_system_snapshot_valid']);

                return $row;
            }, $draft->rows);
            $ready = $this->parser->readiness($id, $rows, $draft->quoteDetails)->ready;
        } catch (RfqParserFailure) {
            $ready = false;
        }
        $this->jobs->refreshStatus($draftId, $ready);
    }

    private function visibleDraft(RfqActor $actor, string $publicId): ?string
    {
        $record = $this->rfqs->findRecord(strtolower(trim($publicId)));
        if ($record === null || !RfqAccessPolicy::canSee($actor, $record->ownership)) {
            return null;
        }

        return StoreProjection::draftId($publicId);
    }

    /**
     * The source's `label_from_rows`, from the uploaded file names.
     *
     * @param list<string> $names
     */
    private static function label(array $names, DateTimeImmutable $now): string
    {
        $names = array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
        if ($names === []) {
            return 'Uploaded files';
        }
        $first = $names[0] . (count($names) > 1 ? ' +' . (count($names) - 1) . ' more' : '');

        return $first . " \u{2013} " . $now->format('d M Y');
    }

    /** @param list<array<string, mixed>> $quotes */
    private static function quoteLabel(array $quotes): string
    {
        $labels = array_values(array_filter(
            array_map(static fn (array $q): string => is_string($q['label'] ?? null) ? trim($q['label']) : '', $quotes),
            static fn (string $label): bool => $label !== '',
        ));

        return mb_substr(implode(' + ', $labels), 0, 255);
    }

    /**
     * The draft header a single imported quote carries (`quote_import.imported_quote_details`):
     * its printed customer, contact, reference, comments, currency and region. The printed
     * 4js customer code is looked up in the customer book and taken only on an exact match;
     * otherwise the printed name stays for the rep to choose. Never the old quote's number.
     *
     * @param array<string, mixed> $details   the header the upload already has (the rep)
     * @param array<string, mixed> $imported
     *
     * @return array<string, mixed>
     */
    private function importedHeader(array $details, array $imported): array
    {
        foreach (['customer_code', 'customer_name', 'contact', 'phone', 'cusref', 'comment', 'currency', 'region'] as $field) {
            $value = $imported[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $details[$field] = $value;
            }
        }
        if (is_array($imported['imported_quote_header'] ?? null)) {
            $details['imported_quote_header'] = $imported['imported_quote_header'];
        }
        $code = is_string($imported['customer_code'] ?? null) ? trim($imported['customer_code']) : '';
        if ($code === '' || $this->customers === null) {
            return $details;
        }
        try {
            foreach ($this->customers->search($code, 10) as $customer) {
                if (strcasecmp($customer->code, $code) === 0) {
                    $details['customer_id'] = (string) $customer->id;
                    $details['customer_code'] = $customer->code;
                    $details['customer_name'] = $customer->name;
                    if ($customer->salesOfficeCode !== '') {
                        $details['salesoffice'] = $customer->salesOfficeCode;
                    }
                    break;
                }
            }
        } catch (RfqLookupUnavailable) {
            // The printed details stay for the rep to confirm; a lookup outage never fails an upload.
        }

        return $details;
    }

    private static function displayName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));

        return $base !== '' ? mb_substr($base, 0, 255) : 'upload';
    }

    /**
     * The correction memory a parse or read is sent. A memory that cannot be
     * read costs it its corrections, never the read.
     *
     * @return array<string, array{product_code: string, confirmations: int}>
     */
    private function corrections(): array
    {
        if ($this->memory === null) {
            return [];
        }
        try {
            return $this->memory->resolved();
        } catch (Throwable) {
            return [];
        }
    }

    private function draftActor(RfqActor $actor, string $email): DraftActor
    {
        return new DraftActor(
            $email,
            RfqAccessPolicy::isManager($actor) ? 'admin' : 'sales',
            $actor->branchCode,
            $this->operators->codeFor($email),
        );
    }
}
