<?php

declare(strict_types=1);

namespace TriCab\Api\Technical;

use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Resolves whether the authenticated user may view Technical Accessories.
 */
final class ResolveTechnicalAccessoriesAccessService
{
    public function __construct(
        private readonly ResolveAuthenticatedCustomerContextService $resolveCustomerContext,
    ) {
    }

    public function allows(AuthenticatedUser $user): bool
    {
        if (TechnicalAccessoriesVisibility::isStaff($user->roles)) {
            return true;
        }

        $customerContext = $this->resolveCustomerContext->execute(
            $user->publicId,
            $user->userId,
            $user->roles,
        );

        $marketCodes = $customerContext->marketCodes ?? [];

        return TechnicalAccessoriesVisibility::allows($user->roles, $marketCodes);
    }
}
