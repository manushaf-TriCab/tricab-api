<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use DateTimeImmutable;
use DateTimeInterface;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;

/**
 * One background read of one document, and what it produced. Contract
 * `rfq.reader-result.v1`.
 *
 * `baseRevision` is the revision the read started from. If the RFQ was saved
 * since, the rows are a proposal rather than a replacement — a stale worker
 * cannot overwrite a rep who saved first (`test_reader_jobs`).
 *
 * `note` is written for the rep ("could not read this file: …"), never a stack
 * trace. `reader` names the engine and version that produced the rows, which
 * is recorded with results (plan 3.6).
 */
final class ReaderResult
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $documentPublicId,
        public readonly ReaderJobStatus $status,
        public readonly int $attempts,
        public readonly ?int $baseRevision,
        public readonly ?string $reader,
        public readonly int $proposedLineCount,
        public readonly ?string $note,
        public readonly DateTimeImmutable $updatedAtUtc,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'documentPublicId' => $this->documentPublicId,
            'status' => $this->status->toString(),
            'attempts' => $this->attempts,
            'baseRevision' => $this->baseRevision,
            'reader' => $this->reader,
            'proposedLineCount' => $this->proposedLineCount,
            'note' => $this->note,
            'updatedAt' => $this->updatedAtUtc->format(DateTimeInterface::ATOM),
        ];
    }
}
