<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Npr\Domain\NprPerson;

/** An Endorsements row as the in-memory repository kept it (a test seam). */
final readonly class RecordedEndorsement
{
    public function __construct(
        public int $revision,
        public string $action,
        public NprPerson $manager,
        public bool $declaration,
        public ?string $comment,
        public DateTimeImmutable $at,
    ) {
    }
}
