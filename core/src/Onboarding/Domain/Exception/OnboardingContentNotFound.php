<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

final class OnboardingContentNotFound extends DomainException
{
    public static function item(string $publicId): self
    {
        return new self(sprintf('No such item %s.', $publicId));
    }

    public static function version(string $publicId): self
    {
        return new self(sprintf('No such version %s.', $publicId));
    }
}
