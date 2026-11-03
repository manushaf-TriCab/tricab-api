<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use TriCab\Core\Rfq\Contract\RfqReaderJobStore;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\StoredReaderJob;

/** The follow-up half of the reader job store, in memory: jobs by id, events and status refreshes recorded. */
final class FollowupReaderJobStore implements RfqReaderJobStore
{
    /** @var array<string, StoredReaderJob> */
    public array $jobs = [];

    /** @var list<array{type: string, message: string}> */
    public array $events = [];

    /** @var list<bool> */
    public array $refreshes = [];

    public function recordEmailProposal(string $draftId, string $jobId, string $fileName, string $messageKey, string $uploaderEmail, int $baseRevision, ?string $note, ?array $proposal): bool
    {
        if (isset($this->jobs[$jobId])) {
            return false;
        }
        $this->jobs[$jobId] = new StoredReaderJob($jobId, $draftId, $fileName, 'message/rfc822', $proposal === null ? 'superseded' : 'done', 1, $baseRevision, $note, $proposal, null, '2026-11-03T00:00:00+00:00', $messageKey);

        return true;
    }

    public function find(string $draftId, string $jobId): ?StoredReaderJob
    {
        $job = $this->jobs[$jobId] ?? null;

        return $job !== null && $job->draftId === $draftId ? $job : null;
    }

    public function forDraft(string $draftId): array
    {
        return array_values(array_filter($this->jobs, static fn (StoredReaderJob $j): bool => $j->draftId === $draftId));
    }

    public function recordEvent(string $draftId, string $eventType, string $message, array $detail = []): void
    {
        $this->events[] = ['type' => $eventType, 'message' => $message];
    }

    public function refreshStatus(string $draftId, bool $ready): void
    {
        $this->refreshes[] = $ready;
    }

    public function enqueue(string $draftId, string $documentId, string $fileName, ?string $contentType, string $uploaderEmail, int $baseRevision): string
    {
        throw new \LogicException('not used');
    }

    public function requeueStale(string $beforeIso, int $maxAttempts): int
    {
        return 0;
    }

    public function nextDraft(): ?string
    {
        return null;
    }

    public function outstanding(): int
    {
        return 0;
    }

    public function claim(string $draftId): array
    {
        return [];
    }

    public function finish(string $jobId, string $status, ?string $note, ?array $proposal = null): void
    {
    }

    public function isUntouched(string $draftId, ?int $baseRevision): bool
    {
        return false;
    }

    public function applyRows(string $draftId, array $rowsByFile, ?int $expectedRevision, string $reader, string $message, array $finish = [], ?DraftActor $actor = null, string $eventType = 'reader_rows_replaced'): ?int
    {
        throw new \LogicException('a follow-up proposal is never applied by the reader');
    }

    public function close(string $draftId, string $jobId, bool $accepted, bool $wasFailure, string $fileName, DraftActor $actor): void
    {
    }
}
