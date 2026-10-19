<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** A person's mark on the thread's current label. */
final readonly class NewDecision
{
    public function __construct(
        public string $assessmentId,
        public string $kind,
        public string $label,
        public int $userId,
        public MailParty $decidedBy,
        public ?string $note,
        public DateTimeImmutable $decidedAt,
    ) {
    }
}
