<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

/**
 * Authenticated actor for Quotes & Orders operations.
 */
final readonly class QuoteOrderActor
{
    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     * @param list<string> $customerPublicIds customer-portal links (Azure); may be empty
     * @param list<string> $allowedCustomerCodes Phase 1 optional legacy codes for portal scope
     */
    public function __construct(
        public int $userId,
        public string $publicId,
        public string $emailAddress,
        public array $permissions,
        public array $roles,
        public array $customerPublicIds = [],
        public array $allowedCustomerCodes = [],
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

    public function isStaff(): bool
    {
        if ($this->isPlatformAdministrator()) {
            return true;
        }

        foreach ($this->roles as $role) {
            $upper = strtoupper($role);
            if ($upper === 'EMPLOYEE') {
                return true;
            }
        }

        return false;
    }

    public function isCustomerPortalScoped(): bool
    {
        if ($this->isStaff() || $this->isPlatformAdministrator()) {
            return false;
        }

        return $this->customerPublicIds !== [];
    }
}
