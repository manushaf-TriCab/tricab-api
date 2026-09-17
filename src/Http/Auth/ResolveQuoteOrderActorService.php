<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerCodesService;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderActor;

/**
 * Builds QuoteOrderActor from request identity.
 *
 * allowedCustomerCodes are resolved server-side from Azure customerPublicIds
 * → Customers.CustomerCode (legacy cust.code). Never taken from the client.
 */
final class ResolveQuoteOrderActorService
{
    public function __construct(
        private readonly ResolveAuthenticatedCustomerContextService $resolveCustomerContext,
        private readonly ResolveAuthenticatedCustomerCodesService $resolveCustomerCodes,
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

        $allowedCustomerCodes = $customerPublicIds === []
            ? []
            : $this->resolveCustomerCodes->execute($customerPublicIds);

        return new QuoteOrderActor(
            userId: $user->userId,
            publicId: $user->publicId,
            emailAddress: $user->emailAddress,
            permissions: $user->permissions,
            roles: $user->roles,
            customerPublicIds: $customerPublicIds,
            allowedCustomerCodes: $allowedCustomerCodes,
        );
    }
}
