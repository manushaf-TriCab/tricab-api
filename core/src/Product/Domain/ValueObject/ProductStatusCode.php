<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Seeded product status codes from Phase 1 reference data.
 * Administrators may extend lookup rows in the database; unknown codes are
 * accepted only via fromString when $strict is false. Domain policies use the
 * known seeded set for transition rules.
 */
final class ProductStatusCode
{
    public const DRAFT = 'draft';
    public const REVIEW = 'review';
    public const PUBLISHED = 'published';
    public const UNPUBLISHED = 'unpublished';
    public const DISCONTINUED = 'discontinued';
    public const ARCHIVED = 'archived';

    private const SEEDED = [
        self::DRAFT,
        self::REVIEW,
        self::PUBLISHED,
        self::UNPUBLISHED,
        self::DISCONTINUED,
        self::ARCHIVED,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || !in_array($normalized, self::SEEDED, true)) {
            throw InvalidProductValue::for('ProductStatusCode', $value);
        }

        return new self($normalized);
    }

    public static function draft(): self
    {
        return new self(self::DRAFT);
    }

    public static function review(): self
    {
        return new self(self::REVIEW);
    }

    public static function published(): self
    {
        return new self(self::PUBLISHED);
    }

    public static function unpublished(): self
    {
        return new self(self::UNPUBLISHED);
    }

    public static function discontinued(): self
    {
        return new self(self::DISCONTINUED);
    }

    public static function archived(): self
    {
        return new self(self::ARCHIVED);
    }

    /**
     * @return list<string>
     */
    public static function seededCodes(): array
    {
        return self::SEEDED;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isDraft(): bool
    {
        return $this->value === self::DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->value === self::PUBLISHED;
    }

    public function isArchived(): bool
    {
        return $this->value === self::ARCHIVED;
    }

    public function isDiscontinued(): bool
    {
        return $this->value === self::DISCONTINUED;
    }

    public function allowsPublicVisibility(): bool
    {
        return $this->value === self::PUBLISHED;
    }
}
