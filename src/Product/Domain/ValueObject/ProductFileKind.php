<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Logical product file kind for upload validation and storage paths.
 */
final class ProductFileKind
{
    public const IMAGE = 'image';
    public const DOCUMENT = 'document';
    public const CAD = 'cad';
    public const AR = 'ar';
    public const FRAME_360 = '360-frame';

    private const ALLOWED = [
        self::IMAGE,
        self::DOCUMENT,
        self::CAD,
        self::AR,
        self::FRAME_360,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || !in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('ProductFileKind', $value);
        }

        return new self($normalized);
    }

    public static function image(): self
    {
        return new self(self::IMAGE);
    }

    public static function document(): self
    {
        return new self(self::DOCUMENT);
    }

    public static function cad(): self
    {
        return new self(self::CAD);
    }

    public static function ar(): self
    {
        return new self(self::AR);
    }

    public static function frame360(): self
    {
        return new self(self::FRAME_360);
    }

    /**
     * @return list<string>
     */
    public static function allowedCodes(): array
    {
        return self::ALLOWED;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isImage(): bool
    {
        return $this->value === self::IMAGE || $this->value === self::FRAME_360;
    }

    public function isDocument(): bool
    {
        return $this->value === self::DOCUMENT;
    }

    public function isCad(): bool
    {
        return $this->value === self::CAD;
    }

    public function isAr(): bool
    {
        return $this->value === self::AR;
    }

    public function is360Frame(): bool
    {
        return $this->value === self::FRAME_360;
    }
}
