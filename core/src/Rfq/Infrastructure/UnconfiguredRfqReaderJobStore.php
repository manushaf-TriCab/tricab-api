<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqReaderJobStore;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\StoredReaderJob;

/**
 * Reader jobs where there is no Azure SQL: nothing is queued, nothing is
 * found, and a write refuses as unavailable (see `UnconfiguredRfqDraftStore`).
 */
final class UnconfiguredRfqReaderJobStore implements RfqReaderJobStore
{
    public function enqueue(string $draftId, string $documentId, string $fileName, ?string $contentType, string $uploaderEmail, int $baseRevision): string
    {
        throw new RfqStoreUnavailable();
    }

    public function recordEmailProposal(string $draftId, string $jobId, string $fileName, string $messageKey, string $uploaderEmail, int $baseRevision, ?string $note, ?array $proposal): bool
    {
        throw new RfqStoreUnavailable();
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
        throw new RfqStoreUnavailable();
    }

    public function forDraft(string $draftId): array
    {
        return [];
    }

    public function find(string $draftId, string $jobId): ?StoredReaderJob
    {
        return null;
    }

    public function isUntouched(string $draftId, ?int $baseRevision): bool
    {
        return false;
    }

    public function applyRows(string $draftId, array $rowsByFile, ?int $expectedRevision, string $reader, string $message, array $finish = [], ?DraftActor $actor = null, string $eventType = 'reader_rows_replaced'): ?int
    {
        throw new RfqStoreUnavailable();
    }

    public function close(string $draftId, string $jobId, bool $accepted, bool $wasFailure, string $fileName, DraftActor $actor): void
    {
        throw new RfqStoreUnavailable();
    }

    public function recordEvent(string $draftId, string $eventType, string $message, array $detail = []): void
    {
        throw new RfqStoreUnavailable();
    }

    public function refreshStatus(string $draftId, bool $ready): void
    {
        throw new RfqStoreUnavailable();
    }
}
