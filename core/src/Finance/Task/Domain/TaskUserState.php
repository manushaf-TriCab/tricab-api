<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/**
 * One person's own marks on one task, like a mailbox's (Nick Han, 23 Oct 2026): when they last opened it,
 * whether they marked it unread again, when they flagged it, when they pinned it. Nobody else sees them.
 */
final readonly class TaskUserState
{
    public function __construct(
        public ?DateTimeImmutable $readAt = null,
        public bool $markedUnread = false,
        public ?DateTimeImmutable $flaggedAt = null,
        public ?DateTimeImmutable $pinnedAt = null,
    ) {
    }
}
