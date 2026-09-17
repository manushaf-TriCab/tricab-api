<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;

/**
 * Builds QuoteOrderActor from request identity. allowedCustomerCodes are never
 * taken from the client — Phase 1 leaves them empty until Azure→legacy code
 * resolution exists (Phase 1B).
 */
final class ResolveQuoteOrderActorService
{
    public function __construct(
        private readonly ResolveAuthenticatedCustomerContextService $resolveCustomerContext,
    ) {
    }

    public function fromRequest(ServerRequestInterface $request): QuoteOrderActor
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            throw new RuntimeException('Authenticated user required.');
        }

        $customerPublicIds = [];
        $context = $this->resolveCustomerContext->execute(
            $user->publicId,
            $user->userId,
            $user->roles,
        );
        if ($context !== null) {
            $customerPublicIds = $context->customerPublicIds;
        }

        return new QuoteOrderActor(
            userId: $user->userId,
            publicId: $user->publicId,
            emailAddress: $user->emailAddress,
            permissions: $user->permissions,
            roles: $user->roles,
            customerPublicIds: $customerPublicIds,
            // Phase 1B: Azure customerPublicId → legacy cust.code not resolved yet.
            allowedCustomerCodes: [],
        );
    }
}
