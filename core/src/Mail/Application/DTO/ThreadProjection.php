<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** What {@see \TriCab\Core\Mail\Domain\Service\ThreadProjector} works out for one thread. */
final readonly class ThreadProjection
{
    public function __construct(
        /** An unclassified thread carries a placeholder `uncertain` label; see `classified`. */
        public ThreadSummary $summary,
        /** False until a message of the thread has been assessed: such a thread is in no list. */
        public bool $classified,
        public ?string $assessmentId,
        public ?string $decisionId,
        public int $unreadCount,
    ) {
    }
}
