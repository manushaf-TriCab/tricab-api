<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Allowed ProductDocuments.AccessCode values (CK_product_ProductDocuments_Access).
 */
final class DocumentAccessCode
{
    public const PUBLIC = 'public';
    public const RESTRICTED = 'restricted';
    public const AUTHENTICATED = 'authenticated';

    private const ALLOWED = [
        self::PUBLIC,
        self::RESTRICTED,
        self::AUTHENTICATED,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || !in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('DocumentAccessCode', $value);
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

    public function isPublic(): bool
    {
        return $this->value === self::PUBLIC;
    }
}
