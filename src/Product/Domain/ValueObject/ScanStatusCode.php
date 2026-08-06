<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Antivirus / content-scan status for uploaded product files.
 */
final class ScanStatusCode
{
    public const NOT_REQUIRED = 'not_required';
    public const PENDING_UPLOAD = 'pending_upload';
    public const PENDING_SCAN = 'pending_scan';
    public const CLEAN = 'clean';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    private const ALLOWED = [
        self::NOT_REQUIRED,
        self::PENDING_UPLOAD,
        self::PENDING_SCAN,
        self::CLEAN,
        self::REJECTED,
        self::FAILED,
        self::SKIPPED,
    ];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || !in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('ScanStatusCode', $value);
        }

        return new self($normalized);
    }

    public static function notRequired(): self
    {
        return new self(self::NOT_REQUIRED);
    }

    public static function pendingUpload(): self
    {
        return new self(self::PENDING_UPLOAD);
    }

    public static function pendingScan(): self
    {
        return new self(self::PENDING_SCAN);
    }

    public static function clean(): self
    {
        return new self(self::CLEAN);
    }

    public static function rejected(): self
    {
        return new self(self::REJECTED);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
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

    public function isClean(): bool
    {
        return $this->value === self::CLEAN;
    }

    public function isPendingScan(): bool
    {
        return $this->value === self::PENDING_SCAN;
    }
}
