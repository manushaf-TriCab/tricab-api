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
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
