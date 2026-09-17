<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\ValueObject;

use InvalidArgumentException;

final class QuoteWriteIdempotencyState
{
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const COMPLETED = 'COMPLETED';
    public const FAILED = 'FAILED';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (!in_array($normalized, [self::IN_PROGRESS, self::COMPLETED, self::FAILED], true)) {
            throw new InvalidArgumentException('Invalid quote write idempotency state: ' . $value);
        }

        return new self($normalized);
    }

    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS);
    }

    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function isCompleted(): bool
    {
        return $this->value === self::COMPLETED;
    }

    public function isInProgress(): bool
    {
        return $this->value === self::IN_PROGRESS;
    }
}
