<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\AuthenticatedCustomerContext;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Resolves trusted customer-portal scope for an authenticated identity user.
 * Scope is derived only from CustomerContactUsers links and assigned roles —
 * never from client-supplied customer/contact IDs.
 */
final class ResolveAuthenticatedCustomerContextService
{
    public function __construct(
        private readonly CustomerContactUserRepository $links,
    ) {
    }

    /**
     * @param list<string> $roleCodes
     */
    public function execute(string $userPublicId, int $userId, array $roleCodes): ?AuthenticatedCustomerContext
    {
        $links = $this->links->listActiveLinksForUser(UserId::fromInt($userId));

        if ($links === []) {
            return null;
        }

        $customerPublicIds = [];
        $contactPublicIds = [];
        foreach ($links as $link) {
            $customerPublicIds[$link->customerPublicId] = true;
            $contactPublicIds[$link->contactPublicId] = true;
        }

        $isCustomerAdmin = false;
        foreach ($roleCodes as $roleCode) {
            if (ProtectedIdentityCatalog::isCustomerFacingRole(RoleCode::fromString($roleCode))
                && strtoupper($roleCode) === ProtectedIdentityCatalog::ROLE_CUSTOMER_ADMIN
            ) {
                $isCustomerAdmin = true;
                break;
            }
        }

        return new AuthenticatedCustomerContext(
            userPublicId: $userPublicId,
            customerPublicIds: array_keys($customerPublicIds),
            contactPublicIds: array_keys($contactPublicIds),
            isCustomerAdmin: $isCustomerAdmin,
        );
    }
}
