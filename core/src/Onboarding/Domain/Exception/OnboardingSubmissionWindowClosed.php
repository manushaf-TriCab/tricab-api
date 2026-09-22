<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * A departed person trying to hand something in.
 *
 * {@see \TriCab\Core\Onboarding\Domain\Service\LeaverPolicy} splits reading from
 * writing on purpose, and this is the write half. Reading survives fourteen days
 * past the departure instant; submitting stops at the instant itself.
 *
 * The asymmetry is the point. Evidence dated after somebody's last day is worse
 * than no evidence at all, because it reads as evidence: an acknowledgement
 * timestamped a week after a person left says they agreed to something while no
 * longer employed, and no later reader can tell whether that was a grace period,
 * a clock skew or somebody else using the account.
 *
 * Distinct from {@see OnboardingAccessWindowClosed}, which refuses a read. Two
 * exceptions rather than one flag, because they answer different questions and
 * carry different codes: the SPA renders "your access has ended" for one and
 * leaves the induction on screen, read-only, for the other.
 *
 * Maps to 403.
 */
final class OnboardingSubmissionWindowClosed extends DomainException
{
    public static function afterDeparture(): self
    {
        return new self(
            'Your record is read-only because your employment has ended. Your existing records are retained.',
        );
    }
}
