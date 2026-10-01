<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * How the optional description-card image is drawn.
 * Missing values resolve to the historical layout: small and centred.
 */
final readonly class DescriptionImagePresentation
{
    public const WIDTH_SMALL = 'small';
    public const WIDTH_MEDIUM = 'medium';
    public const WIDTH_FULL = 'full';
    public const ALIGN_LEFT = 'left';
    public const ALIGN_CENTRE = 'centre';

    private function __construct(
        private string $width,
        private string $alignment,
    ) {
    }

    public static function defaults(): self
    {
        return new self(self::WIDTH_SMALL, self::ALIGN_CENTRE);
    }

    public static function resolve(?string $width, ?string $alignment): self
    {
        return new self(
            self::normalizeWidth($width) ?? self::WIDTH_SMALL,
            self::normalizeAlignment($alignment) ?? self::ALIGN_CENTRE,
        );
    }

    public static function fromInput(?string $width, ?string $alignment): self
    {
        if ($width !== null && self::normalizeWidth($width) === null) {
            throw InvalidProductValue::for('descriptionImageWidth', $width);
        }
        if ($alignment !== null && self::normalizeAlignment($alignment) === null) {
            throw InvalidProductValue::for('descriptionImageAlignment', $alignment);
        }

        return self::resolve($width, $alignment);
    }

    public function width(): string
    {
        return $this->width;
    }

    public function alignment(): string
    {
        return $this->alignment;
    }

    private static function normalizeWidth(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            self::WIDTH_SMALL, self::WIDTH_MEDIUM, self::WIDTH_FULL => $normalized,
            default => null,
        };
    }

    private static function normalizeAlignment(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            self::ALIGN_LEFT => self::ALIGN_LEFT,
            self::ALIGN_CENTRE, 'center' => self::ALIGN_CENTRE,
            default => null,
        };
    }
}
