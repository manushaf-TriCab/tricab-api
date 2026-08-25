<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Inheritable resource types. Additional types can be added without
 * changing hierarchy or mode semantics.
 */
final class StyleResourceType
{
    public const SPECIFICATIONS = 'SPECIFICATIONS';
    public const IMAGES = 'IMAGES';
    public const DOCUMENTS = 'DOCUMENTS';
    public const SPIN_360 = 'SPIN_360';
    public const MODELS_3D = 'MODELS_3D';
    public const AR_ASSETS = 'AR_ASSETS';

    private const ALLOWED = [
        self::SPECIFICATIONS,
        self::IMAGES,
        self::DOCUMENTS,
        self::SPIN_360,
        self::MODELS_3D,
        self::AR_ASSETS,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim(str_replace(['-', ' '], '_', $value)));
        $aliases = [
            'MEDIA' => self::IMAGES,
            'IMAGE' => self::IMAGES,
            'DOCUMENT' => self::DOCUMENTS,
            '360' => self::SPIN_360,
            '360_ASSETS' => self::SPIN_360,
            '3D' => self::MODELS_3D,
            '3D_MODELS' => self::MODELS_3D,
            'AR' => self::AR_ASSETS,
        ];
        $normalized = $aliases[$normalized] ?? $normalized;
        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('StyleResourceType', $value);
        }

        return new self($normalized);
    }

    public static function specifications(): self
    {
        return new self(self::SPECIFICATIONS);
    }

    public static function images(): self
    {
        return new self(self::IMAGES);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return array_map(static fn (string $code): self => new self($code), self::ALLOWED);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return self::ALLOWED;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function isSpecifications(): bool
    {
        return $this->value === self::SPECIFICATIONS;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
