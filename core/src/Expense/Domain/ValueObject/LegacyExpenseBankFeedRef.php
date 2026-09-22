<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Application\Exception\ExpenseLegacyReadOnly;

/**
 * Collision-safe public identifier for a legacy MySQL bank feed row: "legacy-bank:{id}".
 */
final class LegacyExpenseBankFeedRef
{
    public const PREFIX = 'legacy-bank:';

    private function __construct(private readonly int $legacyBankFeedId)
    {
    }

    public static function fromLegacyId(int $legacyBankFeedId): self
    {
        if ($legacyBankFeedId <= 0) {
            throw ExpenseLegacyReadOnly::invalidIdentifier((string) $legacyBankFeedId);
        }

        return new self($legacyBankFeedId);
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

    public function legacyBankFeedId(): int
    {
        return $this->legacyBankFeedId;
    }

    public function toPublicId(): string
    {
        return self::PREFIX . $this->legacyBankFeedId;
    }
}
