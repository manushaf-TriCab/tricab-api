<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

use TriCab\Core\Identity\Domain\Service\StaffEligibilityPolicy;

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
        if (!$this->isStaff()) {
            return false;
        }

        foreach ($this->roles as $role) {
            if (strcasecmp($role, 'ADMINISTRATOR') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Staff by role under {@see StaffEligibilityPolicy}: a customer-oriented
     * role disqualifies the actor whatever staff role it also holds.
     */
    public function isStaff(): bool
    {
        return StaffEligibilityPolicy::isEligible($this->roles);
    }

    /**
     * Everyone who is not eligible staff is limited to their own linked
     * customers, including actors with no links at all: missing, disabled or
     * unresolvable customer context must never read as "not a customer".
     */
    public function isCustomerPortalScoped(): bool
    {
        return !$this->isStaff();
    }
}
