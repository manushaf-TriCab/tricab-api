<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * This person already has an induction record.
 *
 * Maps to 409. One record per person (`UQ_onboarding_TraineeProfiles_UserId`):
 * two would split their evidence between them. The answer carries the existing
 * record's public id, because the thing HR wants next is to open it.
 */
final class OnboardingPersonAlreadyExists extends DomainException
{
    private function __construct(string $message, public readonly ?string $personPublicId)
    {
        parent::__construct($message);
    }

    public static function forPerson(?string $personPublicId): self
    {
        return new self('This person already has an induction record. Open it from People instead.', $personPublicId);
    }
}
