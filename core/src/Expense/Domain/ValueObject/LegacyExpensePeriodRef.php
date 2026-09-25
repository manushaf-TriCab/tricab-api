<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Application\Exception\ExpenseLegacyReadOnly;

/**
 * Collision-safe public identifier for a legacy MySQL period: "legacy-period:{id}".
 */
final class LegacyExpensePeriodRef
{
    public const PREFIX = 'legacy-period:';

    private function __construct(private readonly int $legacyPeriodId)
    {
    }

    public static function fromLegacyId(int $legacyPeriodId): self
    {
        if ($legacyPeriodId <= 0) {
            throw ExpenseLegacyReadOnly::invalidIdentifier((string) $legacyPeriodId);
        }

        return new self($legacyPeriodId);
    }

    public static function tryParse(string $raw): ?self
    {
        $trimmed = strtolower(trim($raw));
        if (!str_starts_with($trimmed, self::PREFIX)) {
            return null;
        }
        $idPart = substr($trimmed, strlen(self::PREFIX));
        if ($idPart === '' || !ctype_digit($idPart)) {
            return null;
        }
        $id = (int) $idPart;
        if ($id <= 0) {
            return null;
        }

        return new self($id);
    }

    public static function assertNotLegacy(string $raw): void
    {
        if (self::tryParse($raw) !== null) {
            throw ExpenseLegacyReadOnly::mutationBlocked();
        }
    }

    public function legacyPeriodId(): int
    {
        return $this->legacyPeriodId;
    }

    public function toPublicId(): string
    {
        return self::PREFIX . $this->legacyPeriodId;
    }
}
