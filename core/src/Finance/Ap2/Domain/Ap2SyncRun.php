<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/** One row of `finance.ap2_sync_runs` (kind `mail`). */
final readonly class Ap2SyncRun
{
    public const RUNNING = 'Running';

    public const SUCCEEDED = 'Succeeded';

    public const FAILED = 'Failed';

    public const CANCELLED = 'Cancelled';

    public function __construct(
        public string $id,
        public string $kind,
        public string $status,
        public DateTimeImmutable $startedAt,
        public ?string $fromDate = null,
        public ?string $toDate = null,
        public int $fetched = 0,
        public int $created = 0,
        public int $skipped = 0,
        public ?string $error = null,
        public ?int $startedByUserId = null,
        public ?DateTimeImmutable $finishedAt = null,
        public int $processed = 0,
        public int $failed = 0,
        /** The last time the run made progress; null on a row written before the column existed (such a row is stale). */
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    /** A running row with no progress for `$minutes` (or none recorded at all) was left by a request that died. */
    public function isStale(DateTimeImmutable $now, int $minutes): bool
    {
        return $this->isRunning() && ($this->updatedAt === null || $this->updatedAt <= $now->modify('-' . $minutes . ' minutes'));
    }
}
