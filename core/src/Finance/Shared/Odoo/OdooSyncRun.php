<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use DateTimeImmutable;

/**
 * One run of the sync (`finance.odoo_sync_runs`), scheduled or asked for.
 */
final readonly class OdooSyncRun
{
    public const MODE_FULL = 'full';

    public const MODE_INCREMENTAL = 'incremental';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_ADMIN = 'admin';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABANDONED = 'abandoned';

    /**
     * @param array<string, array{fetched: int, added: int, updated: int, removed: int}> $counts per list
     */
    public function __construct(
        public string $id,
        public string $mode,
        public string $triggeredBy,
        public string $status,
        public array $counts,
        public ?string $error,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt = null,
        public ?int $actorUserId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'trigger' => $this->triggeredBy,
            'status' => $this->status,
            'counts' => $this->counts,
            'error' => $this->error,
            'started_at' => $this->startedAt->format('c'),
            'finished_at' => $this->finishedAt?->format('c'),
        ];
    }
}
