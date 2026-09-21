<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;

/**
 * The status may be set by this actor in general, but not from where the work
 * currently is, because completed work is locked.
 *
 * Only a reviewer can reopen completed work, and only by sending it back with a
 * reason — never by silently reverting it to in progress. Someone arriving at a
 * completed item is looking at a stale tab, so this carries a plain explanation
 * and a 409 rather than a refusal.
 */
final class AssignmentStatusIsFinal extends DomainException
{
    private function __construct(
        string $message,
        public readonly StatusActor $actor,
        public readonly AssignmentStatus $from,
    ) {
        parent::__construct($message);
    }

    public static function forActor(StatusActor $actor, AssignmentStatus $from): self
    {
        return new self(
            'That work is already complete. Reload the page to see its current state.',
            $actor,
            $from,
        );
    }
}
