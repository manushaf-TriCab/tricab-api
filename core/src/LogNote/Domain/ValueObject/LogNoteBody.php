<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Domain\ValueObject;

use TriCab\Core\LogNote\Domain\Exception\InvalidLogNoteValue;

final class LogNoteBody
{
    public const MAX_LENGTH = 20000;
    public const PREVIEW_LENGTH = 180;

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);
        if ($normalized === '') {
            throw InvalidLogNoteValue::for('Body', $value);
        }

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidLogNoteValue::for('Body', 'exceeds maximum length');
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function preview(): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $this->value) ?? $this->value;
        if (mb_strlen($collapsed) <= self::PREVIEW_LENGTH) {
            return $collapsed;
        }

        return rtrim(mb_substr($collapsed, 0, self::PREVIEW_LENGTH)) . '…';
    }
}
