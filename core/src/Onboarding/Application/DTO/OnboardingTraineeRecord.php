<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use DateTimeImmutable;

/**
 * The induction record itself — one row per person, keyed on
 * `identity.Users.UserId` and carrying no identity of its own.
 *
 * Only the fields the person's own view needs are here. [Position],
 * [Department] and [Branch] are HR's description of the job and are not in this
 * projection: a trainee does not need them to do their induction, and a field a
 * projection does not carry is a field that cannot leak through it.
 *
 * `$departedAtUtc` is here because the leaver window is decided from it and
 * {@see \TriCab\Core\Onboarding\Domain\Service\LeaverPolicy} is the authority
 * on what it means. Note it may be in the FUTURE: HR records a last day weeks
 * ahead, and somebody serving out notice has not left.
 */
final readonly class OnboardingTraineeRecord
{
    public function __construct(
        public string $publicId,
        public ?string $startDate,
        public ?DateTimeImmutable $departedAtUtc,
    ) {
    }
}
