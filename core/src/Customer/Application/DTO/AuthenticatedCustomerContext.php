<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

/**
 * Resolved customer-portal scope for an authenticated user. Consumed by later
 * stages (authorization / portal API) to determine which customers and
 * contacts the current session may act on behalf of.
 */
final readonly class AuthenticatedCustomerContext
{
    /**
     * @param list<string> $customerPublicIds
     * @param list<string> $contactPublicIds
     */
    public function __construct(
        public string $userPublicId,
        public array $customerPublicIds,
        public array $contactPublicIds,
        public bool $isCustomerAdmin,
    ) {
    }
}
