<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application;

use InvalidArgumentException;

/**
 * Temporary authorization cutover switch for TriExpense.
 *
 * - legacy: ExpenseProfile.IsFinance / IsExpenseAdmin only (pre-migration)
 * - hybrid: OR of legacy flags and TRIEXPENSE.* permissions; mismatches logged
 * - permissions: TRIEXPENSE.* permissions are authoritative
 */
final class ExpensePermissionMode
{
    public const LEGACY = 'legacy';
    public const HYBRID = 'hybrid';
    public const PERMISSIONS = 'permissions';

    /** @var list<string> */
    public const ALL = [self::LEGACY, self::HYBRID, self::PERMISSIONS];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $normalized = strtolower(trim($raw));
        if (!in_array($normalized, self::ALL, true)) {
            throw new InvalidArgumentException(
                'TRIEXPENSE_PERMISSION_MODE must be legacy, hybrid, or permissions.',
            );
        }

        return new self($normalized);
    }

    public static function legacy(): self
    {
        return new self(self::LEGACY);
    }

    public static function hybrid(): self
    {
        return new self(self::HYBRID);
    }

    public static function permissions(): self
    {
        return new self(self::PERMISSIONS);
    }

    public static function default(): self
    {
        return self::hybrid();
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isLegacy(): bool
    {
        return $this->value === self::LEGACY;
    }

    public function isHybrid(): bool
    {
        return $this->value === self::HYBRID;
    }

    public function isPermissions(): bool
    {
        return $this->value === self::PERMISSIONS;
    }

    public function usesLegacyFlags(): bool
    {
        return $this->isLegacy() || $this->isHybrid();
    }

    public function usesPermissionCodes(): bool
    {
        return $this->isPermissions() || $this->isHybrid();
    }
}
