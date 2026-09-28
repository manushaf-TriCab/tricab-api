<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use DomainException;

/**
 * A state change the rules refuse.
 *
 * Carries which machine refused it and why, so an endpoint can answer 403
 * (this actor may never do this) or 409 (not from where the record is now)
 * without parsing the message. The split follows `AssignmentStatusPolicy` in
 * Onboarding: "you may never" is reported before "not from here".
 */
final class RfqTransitionNotPermitted extends DomainException
{
    public const REASON_ACTOR = 'actor';
    public const REASON_STATE = 'state';
    public const REASON_INPUT = 'input';

    private function __construct(
        string $message,
        public readonly string $machine,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function forActor(string $machine, string $actor, string $to): self
    {
        return new self(
            sprintf('%s may not set %s to "%s".', ucfirst($actor), $machine, $to),
            $machine,
            self::REASON_ACTOR,
        );
    }

    public static function fromState(string $machine, string $from, string $to): self
    {
        return new self(
            sprintf('%s cannot move from "%s" to "%s".', ucfirst($machine), $from, $to),
            $machine,
            self::REASON_STATE,
        );
    }

    public static function missingInput(string $machine, string $what): self
    {
        return new self(sprintf('%s: %s', ucfirst($machine), $what), $machine, self::REASON_INPUT);
    }
}
