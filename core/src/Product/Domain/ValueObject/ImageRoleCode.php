<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Allowed ProductImages.ImageRoleCode values (CK_product_ProductImages_Role).
 * Admin API field mediaCategoryCode maps to this role for image media rows;
 * ProductMedia.MediaCategoryCode remains the registry value "image".
 */
final class ImageRoleCode
{
    public const PRIMARY = 'primary';
    public const GALLERY = 'gallery';
    public const THUMBNAIL = 'thumbnail';
    public const HERO = 'hero';
    public const DIAGRAM = 'diagram';
    public const CROSS_SECTION = 'cross-section';
    public const INSTALLATION = 'installation';

    private const ALLOWED = [
        self::PRIMARY,
        self::GALLERY,
        self::THUMBNAIL,
        self::HERO,
        self::DIAGRAM,
        self::CROSS_SECTION,
        self::INSTALLATION,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || !in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('ImageRoleCode', $value);
        }

        return new self($normalized);
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
}
