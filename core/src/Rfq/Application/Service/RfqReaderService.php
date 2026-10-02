<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use DateTimeImmutable;
use Throwable;
use TriCab\Core\Rfq\Application\DTO\ReaderResult;
use TriCab\Core\Rfq\Application\DTO\SourceDocument;
use TriCab\Core\Rfq\Application\DTO\UploadedRfq;
use TriCab\Core\Rfq\Contract\RfqCorrectionMemory;
use TriCab\Core\Rfq\Contract\RfqDocumentStore;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Contract\RfqReaderJobStore;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
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
        $parsed = $this->parser->parse(ParseRequest::forDocuments($id, $documents, $this->allowModel)->withCorrections($this->corrections()));
        $routes = [];
        foreach ($parsed->documents as $summary) {
            $routes[$summary['documentPublicId']] = $summary['readerRoute'];
        }
        $queueable = array_filter($documents, static fn (array $d): bool => $routes[$d['documentPublicId']] ?? false);
        if ($parsed->lines === [] && $queueable === []) {
            throw InvalidRfqValue::required('RFQ lines', 'nothing in the files could be read as a line.');
        }

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
        $draftId = $this->drafts->create(new NewDraft(
            source: 'web',
            label: self::label(array_column($documents, 'fileName'), $this->clock->now()),
            quoteDetails: $rep['quoteDetails'],
            rows: $rows,
            emailMeta: $meta,
            creatorEmail: $email,
            branch: $worker->branch ?? '',
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
     * `$maxDrafts` drafts' queued documents.
     *
     * @return array{recovered: int, drafts: int, jobs: int, applied: int, proposed: int, failed: int, retried: int, superseded: int}
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

        return $summary;
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
