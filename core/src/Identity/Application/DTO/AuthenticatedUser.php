<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

use TriCab\Core\Identity\Domain\Entity\User;

/**
 * Framework-neutral authenticated user projection for API consumers.
 */
final readonly class AuthenticatedUser
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        public int $userId,
        public string $publicId,
        public string $emailAddress,
        public string $displayName,
        public string $status,
        public array $roles,
        public array $permissions,
    ) {
    }

    public static function fromUser(User $user): self
    {
        $roles = [];
        foreach ($user->roles() as $role) {
            $roles[] = $role->toString();
        }

        $permissions = [];
        foreach ($user->permissions() as $permission) {
            $permissions[] = $permission->toString();
        }

        return new self(
            $user->id()->toInt(),
            $user->publicId()->toString(),
            $user->emailAddress()->toString(),
            $user->displayName(),
            $user->status()->toString(),
            $roles,
            $permissions,
        );
    }
}
