<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;

/**
 * This actor may never set this status, from anywhere.
 *
 * Distinct from {@see AssignmentStatusIsFinal} on purpose: this one is a client
 * bug or an attempt, and maps to 403. The other is almost always a stale tab,
 * and maps to 409. Telling them apart shortens both conversations, and
 * collapsing them would hide a real client defect behind a reload prompt.
 */
final class StatusChangeNotPermitted extends DomainException
{
    private function __construct(
        string $message,
        public readonly StatusActor $actor,
        public readonly AssignmentStatus $to,
    ) {
        parent::__construct($message);
    }

    public static function forActor(StatusActor $actor, AssignmentStatus $to): self
    {
        return new self(
            sprintf('A %s cannot set this work to "%s".', $actor->toString(), $to->label()),
            $actor,
            $to,
        );
    }
}
