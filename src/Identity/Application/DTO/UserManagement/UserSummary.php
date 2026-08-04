<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserSummary
{
    /**
     * @param list<string> $roles
     * @param list<string> $providers
     */
    public function __construct(
        public string $publicId,
        public string $emailAddress,
        public string $displayName,
        public string $status,
        public array $roles,
        public array $providers,
        public string $createdAtUtc,
        public string $updatedAtUtc,
        public ?string $lastLoginAtUtc = null,
    ) {
    }
}
