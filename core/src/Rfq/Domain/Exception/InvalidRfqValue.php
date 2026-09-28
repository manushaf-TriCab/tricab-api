<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use InvalidArgumentException;

/** A value outside the RFQ vocabulary, arriving from a client. Maps to 400. */
final class InvalidRfqValue extends InvalidArgumentException
{
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

    public static function required(string $type, string $reason): self
    {
        return new self(sprintf('A %s is required: %s', $type, $reason));
    }

    public static function outOfRange(string $type, int $value, int $min, int $max): self
    {
        return new self(sprintf('Invalid %s: %d. Must be between %d and %d.', $type, $value, $min, $max));
    }
}
