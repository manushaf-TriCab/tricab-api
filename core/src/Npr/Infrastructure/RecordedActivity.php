<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Npr\Domain\RequestStatus;

/** An Activity row as the in-memory repository kept it (a test seam). */
final readonly class RecordedActivity
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $revision,
        public NprPerson $actor,
        public string $actorRole,
        public string $action,
        public RequestStatus $before,
        public RequestStatus $after,
        public string $summary,
        public array $payload,
        public DateTimeImmutable $at,
    ) {
    }
}
