<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Six-digit HEX colour for product thumbnail/card presentation backgrounds.
 */
final readonly class ThumbnailBackgroundColor
{
    private const PATTERN = '/^#([0-9A-Fa-f]{6})$/';

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $raw): self
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw InvalidProductValue::for('ThumbnailBackgroundColor', $raw);
        }

        if (!preg_match(self::PATTERN, $trimmed, $matches)) {
            throw InvalidProductValue::for('ThumbnailBackgroundColor', $raw);
        }

        return new self('#' . strtoupper($matches[1]));
    }

    public static function tryFromString(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        try {
            return self::fromString($trimmed);
        } catch (InvalidProductValue) {
            return null;
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
