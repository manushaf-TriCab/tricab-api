<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

/** Future migration dry-run classification — not executed in Stage 2. */
final class ExpenseMigrationReadiness
{
    public const READY = 'READY';
    public const OWNER_UNMATCHED = 'OWNER_UNMATCHED';
    public const MANAGER_UNMATCHED = 'MANAGER_UNMATCHED';
    public const CATEGORY_REVIEW = 'CATEGORY_REVIEW';
    public const ATTACHMENT_REVIEW = 'ATTACHMENT_REVIEW';
    public const OTHER_REVIEW = 'OTHER_REVIEW';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $upper = strtoupper(trim($value));
        $allowed = [
            self::READY,
            self::OWNER_UNMATCHED,
            self::MANAGER_UNMATCHED,
            self::CATEGORY_REVIEW,
            self::ATTACHMENT_REVIEW,
            self::OTHER_REVIEW,
        ];
        if (!in_array($upper, $allowed, true)) {
            return new self(self::OTHER_REVIEW);
        }

        return new self($upper);
    }

    public function toString(): string
    {
        return $this->value;
    }
}