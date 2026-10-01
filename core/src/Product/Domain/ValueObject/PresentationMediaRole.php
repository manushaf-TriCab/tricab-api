<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Canonical independent presentation roles stored in ProductImageRoleAssignments.
 * Distinct from legacy ProductImages.ImageRoleCode (which remains for gallery/compat).
 */
final class PresentationMediaRole
{
    public const PRODUCT_THUMBNAIL = 'product-thumbnail';
    public const PRIMARY = 'primary';
    /** Optional second image in the left product-page column. At most one. */
    public const PRIMARY_SECONDARY = 'primary-secondary';
    public const CROSS_SECTION = 'cross-section';

    private const ALLOWED = [
        self::PRODUCT_THUMBNAIL,
        self::PRIMARY,
        self::PRIMARY_SECONDARY,
        self::CROSS_SECTION,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || !in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('PresentationMediaRole', $value);
        }

        return new self($normalized);
    }

    public static function isPresentationRole(string $value): bool
    {
        return in_array(strtolower(trim($value)), self::ALLOWED, true);
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
}
