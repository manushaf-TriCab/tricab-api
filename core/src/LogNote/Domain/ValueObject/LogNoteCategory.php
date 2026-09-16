<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Domain\ValueObject;

use TriCab\Core\LogNote\Domain\Exception\InvalidLogNoteValue;

final class LogNoteCategory
{
    public const STANDARD = 'standard';
    public const NICHE = 'niche';
    public const ACCOUNTS = 'accounts';

    /** @var array<string, string> */
    public const LABELS = [
        self::STANDARD => 'Standard',
        self::NICHE => 'Margin',
        self::ACCOUNTS => 'Accounts',
    ];

    /** @var list<string> */
    public const CODES = [
        self::STANDARD,
        self::NICHE,
        self::ACCOUNTS,
    ];

    private function __construct(private readonly string $code)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, self::CODES, true)) {
            throw InvalidLogNoteValue::for('Category', $value);
        }

        return new self($normalized);
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    public function toString(): string
    {
        return $this->code;
    }

    public function label(): string
    {
        return self::LABELS[$this->code];
    }
}
