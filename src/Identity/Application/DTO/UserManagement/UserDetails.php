<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserDetails
{
    /**
     * @param list<UserRoleAssignment> $roles
     * @param list<EffectivePermissionGrant> $effectivePermissions
     * @param list<IdentityProviderSummary> $identityProviders
     * @param list<UserManagementAuditEntry> $recentAuditEvents
     */
    public function __construct(
        public string $publicId,
        public string $emailAddress,
        public string $displayName,
        public string $status,
        public array $roles,
        public array $effectivePermissions,
        public array $identityProviders,
        public string $createdAtUtc,
        public string $updatedAtUtc,
        public ?string $lastLoginAtUtc = null,
        public array $recentAuditEvents = [],
    ) {
    }
}
