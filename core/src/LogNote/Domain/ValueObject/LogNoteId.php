<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Domain\ValueObject;

use TriCab\Core\LogNote\Domain\Exception\InvalidLogNoteValue;

final class LogNoteId
{
    private function __construct(private readonly int $value)
    {
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw InvalidLogNoteValue::for('LogNoteId', (string) $value);
        }

        return new self($value);
    }

    public function toInt(): int
    {
        return $this->value;
    }
}
