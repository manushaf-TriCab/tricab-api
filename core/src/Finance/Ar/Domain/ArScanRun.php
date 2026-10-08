<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

use DateTimeImmutable;

/** One row of `ar_scan_runs`: a watermark scan or a period scan, and where it stands. */
final readonly class ArScanRun
{
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const STOPPED = 'stopped';

    public function __construct(
        public string $id,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public string $mailbox,
        public string $status,
        public ?DateTimeImmutable $watermark,
        public int $totalFetched,
        public int $newRecords,
        public int $skippedRecords,
        public int $failedEnrichments,
        public string $errors,
        public ?string $fromDate,
        public ?string $toDate,
        public ?int $requestedByUserId,
        public string $requestedBy,
        public int $totalMessages,
        public int $processedMessages,
        public ?DateTimeImmutable $claimExpiresAt,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
