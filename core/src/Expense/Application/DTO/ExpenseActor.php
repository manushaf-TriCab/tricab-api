<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\DTO;

use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Authenticated TriCab actor for Tri Expense operations.
 */
final class ExpenseActor
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public function __construct(
        public readonly UserId $userId,
        public readonly string $publicId,
        public readonly string $emailAddress,
        public readonly array $permissions,
        public readonly array $roles,
    ) {
    }

    public function hasPermission(string $code): bool
    {
        foreach ($this->permissions as $permission) {
            if (strcasecmp($permission, $code) === 0) {
                return true;
            }
        }

        return false;
    }

    public function isPlatformAdministrator(): bool
    {
        foreach ($this->roles as $role) {
            if (strcasecmp($role, 'ADMINISTRATOR') === 0) {
                return true;
            }
        }

        return false;
    }
}