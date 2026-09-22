<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DateTimeImmutable;
use DomainException;

/**
 * A departed person asking for their own records after the fourteen-day read
 * window has run out.
 *
 * Raised rather than answered with an empty list, which was the alternative and
 * is worse: an empty induction reads as "you have nothing to do", and a person
 * chasing a payslip or a signed policy would believe the records were gone.
 * They are not gone — the leaver decision of 21 Sep 2026 is about ACCESS, and
 * retention is a separate figure that has not been set, so nothing has been
 * deleted. Saying so is what lets them ask HR instead of giving up.
 *
 * Maps to 403: the request was understood and refused, and no credential the
 * person can present would change it.
 */
final class OnboardingAccessWindowClosed extends DomainException
{
    private function __construct(
        string $message,
        public readonly DateTimeImmutable $accessEndedAtUtc,
    ) {
        parent::__construct($message);
    }

    public static function endedAt(DateTimeImmutable $accessEndedAtUtc): self
    {
        return new self(
            sprintf(
                'Access to these onboarding records ended on %s. The records are retained; ask HR.',
                $accessEndedAtUtc->format('j F Y'),
            ),
            $accessEndedAtUtc,
        );
    }
}
