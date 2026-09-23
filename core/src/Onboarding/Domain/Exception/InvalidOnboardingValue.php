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
     * A value that is missing where the rule needs one, with the reason in
     * words a person can act on — "Invalid note: ''" tells them nothing.
     */
    public static function required(string $type, string $reason): self
    {
        return new self(sprintf('A %s is required: %s', $type, $reason));
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
