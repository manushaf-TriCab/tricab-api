<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use DateTimeImmutable;

/** One `ar_scan_runs` row to write. */
final readonly class ArMigratedScanRun
{
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
        public ?string $errors,
        public ?string $fromDate,
        public ?string $toDate,
        public ?int $requestedByUserId,
        public string $requestedBy,
        public int $totalMessages,
        public int $processedMessages,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
