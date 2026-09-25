<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

final class OnboardingPersonNotFound extends DomainException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('No induction record %s.', $publicId));
    }
}
