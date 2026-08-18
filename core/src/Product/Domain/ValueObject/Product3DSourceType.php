<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class Product3DSourceType
{
    public const SPECIFICATION = 'specification';
    public const IMAGE_GENERATION = 'image_generation';
    public const UPLOAD = 'upload';

    private const ALLOWED = [self::SPECIFICATION, self::IMAGE_GENERATION, self::UPLOAD];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || !in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('Product3DSourceType', $value);
        }

        return new self($normalized);
    }

    public static function upload(): self
    {
        return new self(self::UPLOAD);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
