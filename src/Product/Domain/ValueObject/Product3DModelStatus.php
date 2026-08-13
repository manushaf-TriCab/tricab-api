<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class Product3DModelStatus
{
    public const DRAFT = 'draft';
    public const PROCESSING = 'processing';
    public const READY = 'ready';
    public const FAILED = 'failed';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';

    private const ALLOWED = [
        self::DRAFT,
        self::PROCESSING,
        self::READY,
        self::FAILED,
        self::PUBLISHED,
        self::ARCHIVED,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || !in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('Product3DModelStatus', $value);
        }

        return new self($normalized);
    }

    public static function draft(): self
    {
        return new self(self::DRAFT);
    }

    public static function ready(): self
    {
        return new self(self::READY);
    }

    public static function published(): self
    {
        return new self(self::PUBLISHED);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function canPublish(): bool
    {
        return $this->value === self::READY || $this->value === self::PUBLISHED;
    }

    public function isPublished(): bool
    {
        return $this->value === self::PUBLISHED;
    }
}
