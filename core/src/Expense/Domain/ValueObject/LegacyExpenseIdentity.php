<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

/**
 * Migration/audit provenance for legacy string identities. Not a V2 auth principal.
 */
final class LegacyExpenseIdentity
{
    private function __construct(
        private readonly ?string $createdBy,
        private readonly ?string $submittedBy,
        private readonly ?string $managerLogin,
        private readonly ?int $legacyReceiptId,
        private readonly ?string $legacyFileUrl,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, null, null, null);
    }

    public static function of(
        ?string $createdBy = null,
        ?string $submittedBy = null,
        ?string $managerLogin = null,
        ?int $legacyReceiptId = null,
        ?string $legacyFileUrl = null,
    ): self {
        return new self(
            self::norm($createdBy),
            self::norm($submittedBy),
            self::norm($managerLogin),
            $legacyReceiptId !== null && $legacyReceiptId > 0 ? $legacyReceiptId : null,
            self::norm($legacyFileUrl),
        );
    }

    private static function norm(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    public function submittedBy(): ?string
    {
        return $this->submittedBy;
    }

    public function managerLogin(): ?string
    {
        return $this->managerLogin;
    }

    public function legacyReceiptId(): ?int
    {
        return $this->legacyReceiptId;
    }

    public function legacyFileUrl(): ?string
    {
        return $this->legacyFileUrl;
    }
}