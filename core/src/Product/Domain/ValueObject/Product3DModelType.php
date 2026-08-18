<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class Product3DModelType
{
    public const PROCEDURAL = 'procedural';
    public const GLB = 'glb';
    public const GLTF = 'gltf';

    private const ALLOWED = [self::PROCEDURAL, self::GLB, self::GLTF];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || !in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('Product3DModelType', $value);
        }

        return new self($normalized);
    }

    public static function fromFileExtension(string $extension): self
    {
        return self::fromString(strtolower(trim($extension)));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
