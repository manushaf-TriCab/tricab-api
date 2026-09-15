<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Application\Exception\ExpenseLegacyReadOnly;

/**
 * Collision-safe public identifier for a legacy MySQL receipt: "legacy:{id}".
 */
final class LegacyExpenseReceiptRef
{
    public const PREFIX = 'legacy:';

    private function __construct(private readonly int $legacyReceiptId)
    {
    }

    public static function fromLegacyId(int $legacyReceiptId): self
    {
        if ($legacyReceiptId <= 0) {
            throw ExpenseLegacyReadOnly::invalidIdentifier((string) $legacyReceiptId);
        }

        return new self($legacyReceiptId);
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

    public function legacyReceiptId(): int
    {
        return $this->legacyReceiptId;
    }

    public function toPublicId(): string
    {
        return self::PREFIX . $this->legacyReceiptId;
    }
}
