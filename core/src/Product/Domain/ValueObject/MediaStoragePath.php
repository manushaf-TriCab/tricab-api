<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Blob storage relative path (not a free-form executable URL).
 */
final class MediaStoragePath
{
    private const MAX_LENGTH = 1000;
    private const PATTERN = '/^[A-Za-z0-9._\/-]+$/';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);

        if (
            $normalized === ''
            || mb_strlen($normalized) > self::MAX_LENGTH
            || str_contains($normalized, '..')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized) === 1
            || preg_match(self::PATTERN, $normalized) !== 1
        ) {
            throw InvalidProductValue::for('MediaStoragePath', $value);
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
