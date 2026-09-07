<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Entity;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Module authorization/profile metadata linked to identity.User — not a second login.
 */
final class ExpenseProfile
{
    public function __construct(
        private readonly UserId $userId,
        private readonly string $legacyLogin,
        private readonly string $legacyEmail,
        private readonly bool $isActive,
        private readonly bool $isFinance,
        private readonly bool $isAdmin,
        private readonly ?string $managerLegacyLogin,
        private readonly ?UserId $managerUserId,
    ) {
        if (trim($legacyLogin) === '') {
            throw InvalidExpenseValue::for('legacyLogin', $legacyLogin);
        }
        if (trim($legacyEmail) === '' || !str_contains($legacyEmail, '@')) {
            throw InvalidExpenseValue::for('legacyEmail', $legacyEmail);
        }
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function legacyLogin(): string
    {
        return $this->legacyLogin;
    }

    public function legacyEmail(): string
    {
        return strtolower($this->legacyEmail);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isFinance(): bool
    {
        return $this->isFinance;
    }

    /** Tri Expense admin — independent of platform ADMINISTRATOR. */
    public function isExpenseAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function managerLegacyLogin(): ?string
    {
        return $this->managerLegacyLogin;
    }

    public function managerUserId(): ?UserId
    {
        return $this->managerUserId;
    }
}