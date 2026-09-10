<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Domain\ValueObject;

use TriCab\Core\LogNote\Domain\Exception\InvalidLogNoteValue;

final class LogNoteInteractionType
{
    public const CONFERENCE = 'Conference';
    public const CUSTOMER = 'Customer';
    public const ONLINE_MEETING = 'Online Meeting';
    public const PHONE_CALL = 'Phone Call';
    public const DEFAULT = self::PHONE_CALL;

    /** @var array<string, string> */
    public const LABELS = [
        self::CONFERENCE => 'Conference',
        self::CUSTOMER => 'Customer Visit',
        self::ONLINE_MEETING => 'Online Meeting',
        self::PHONE_CALL => 'Phone Call',
    ];

    /** @var list<string> */
    public const CODES = [
        self::CONFERENCE,
        self::CUSTOMER,
        self::ONLINE_MEETING,
        self::PHONE_CALL,
    ];

    private function __construct(private readonly string $code)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);
        if (!in_array($normalized, self::CODES, true)) {
            throw InvalidLogNoteValue::for('InteractionType', $value);
        }

        return new self($normalized);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
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
