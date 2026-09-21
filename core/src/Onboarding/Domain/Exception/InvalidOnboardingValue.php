<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use InvalidArgumentException;

final class InvalidOnboardingValue extends InvalidArgumentException
{
    public static function for(string $type, string $value): self
    {
        return new self(sprintf('Invalid %s: "%s".', $type, $value));
    }

    /**
     * @param list<string> $allowed
     */
    public static function forOneOf(string $type, string $value, array $allowed): self
    {
        return new self(sprintf(
            'Invalid %s: "%s". Must be one of: %s.',
            $type,
            $value,
            implode(', ', $allowed),
        ));
    }
}
