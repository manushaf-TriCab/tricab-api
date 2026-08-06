<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Product media/document blob upload lifecycle status.
 */
final class UploadStatusCode
{
    public const PENDING_UPLOAD = 'pending_upload';
    public const UPLOADED = 'uploaded';
    public const ACTIVE = 'active';
    public const FAILED = 'failed';
    public const DELETED = 'deleted';
    public const REPLACED = 'replaced';

    private const ALLOWED = [
        self::PENDING_UPLOAD,
        self::UPLOADED,
        self::ACTIVE,
        self::FAILED,
        self::DELETED,
        self::REPLACED,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || !in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('UploadStatusCode', $value);
        }

        return new self($normalized);
    }

    public static function pendingUpload(): self
    {
        return new self(self::PENDING_UPLOAD);
    }

    public static function uploaded(): self
    {
        return new self(self::UPLOADED);
    }

    public static function active(): self
    {
        return new self(self::ACTIVE);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public static function deleted(): self
    {
        return new self(self::DELETED);
    }

    public static function replaced(): self
    {
        return new self(self::REPLACED);
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

    public function isPendingUpload(): bool
    {
        return $this->value === self::PENDING_UPLOAD;
    }

    public function isUploaded(): bool
    {
        return $this->value === self::UPLOADED;
    }

    public function isActive(): bool
    {
        return $this->value === self::ACTIVE;
    }

    public function isDeleted(): bool
    {
        return $this->value === self::DELETED;
    }
}
