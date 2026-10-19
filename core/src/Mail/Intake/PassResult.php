<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

/** What one pass did, counts only (never mail text). */
final class PassResult
{
    public const OFF = 'off';
    public const PAUSED = 'paused';
    public const DONE = 'done';

    public int $pages = 0;
    public int $received = 0;
    public int $recorded = 0;
    public int $duplicates = 0;
    public int $skipped = 0;
    public int $classified = 0;
    public int $uncertainFailures = 0;
    public int $removed = 0;
    public int $gone = 0;
    public bool $resynced = false;
    public bool $touchedDatabase = false;

    public function __construct(public readonly string $status)
    {
    }

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'pages' => $this->pages,
            'received' => $this->received,
            'recorded' => $this->recorded,
            'duplicates' => $this->duplicates,
            'skipped' => $this->skipped,
            'classified' => $this->classified,
            'uncertainFailures' => $this->uncertainFailures,
            'removed' => $this->removed,
            'gone' => $this->gone,
            'resynced' => $this->resynced,
            'touchedDatabase' => $this->touchedDatabase,
        ];
    }
}
