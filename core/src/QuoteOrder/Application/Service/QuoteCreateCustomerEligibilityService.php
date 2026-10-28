<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteCreateAuthorizationException;
use TriCab\Core\QuoteOrder\Module;

/**
 * Authoritative create-customer eligibility. Not used for quote READ visibility.
 *
 * Portal: linked CustomerContactUsers only.
 * Staff: QUOTE.CREATE + same commercial capability as price-as
 *        (CUSTOMER.PRICE_AS, eligible staff identity per StaffEligibilityPolicy)
 *        — not CUSTOMER.READ, QUOTE.READ or staffSearchAll. An HTTP caller must pass
 *        the assurance-scoped user from ResolveRequestAuthenticatedUser.
 *
 * Territory scoping from legacy picker UI is NOT re-enforced server-side today
 * (legacy switch also did not re-check territory) — future hardening.
 */
final class QuoteCreateCustomerEligibilityService
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ResolveAuthenticatedCustomerContextService $customerContext,
    ) {
    }

    public function assertCanCreateQuoteFor(AuthenticatedUser $actor, string $customerPublicId): void
    {
        if (!$this->hasCreatePermission($actor)) {
            throw QuoteCreateAuthorizationException::missingPermission();
        }

        $normalized = strtolower(trim($customerPublicId));
        if ($normalized === '') {
            throw QuoteCreateAuthorizationException::customerNotFound();
        }

        $details = $this->customers->findDetailsByPublicId(CustomerPublicId::fromString($normalized));
        if ($details === null || !$details->isActive) {
            throw QuoteCreateAuthorizationException::customerNotFound();
        }

        if ($this->isPortalActor($actor)) {
            $scope = $this->customerContext->execute($actor->publicId, $actor->userId, $actor->roles);
            if ($scope === null) {
                throw QuoteCreateAuthorizationException::customerNotAllowed($normalized);
            }
            foreach ($scope->customerPublicIds as $linked) {
                if (strcasecmp($linked, $normalized) === 0) {
                    return;
                }
            }
            throw QuoteCreateAuthorizationException::customerNotAllowed($normalized);
        }

        if (!$this->staffMaySelectCustomer($actor)) {
            throw QuoteCreateAuthorizationException::customerNotAllowed($normalized);
        }
    }

    public function canCreateQuoteFor(AuthenticatedUser $actor, string $customerPublicId): bool
    {
        try {
            $this->assertCanCreateQuoteFor($actor, $customerPublicId);

            return true;
        } catch (QuoteCreateAuthorizationException) {
            return false;
        }
    }

    public function hasCreatePermission(AuthenticatedUser $actor): bool
    {
        foreach ($actor->permissions as $permission) {
            if (strcasecmp($permission, Module::PERMISSION_CREATE) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same rule as ResolvePricingActorContextService::actorMayPriceAs.
     */
    public function staffMaySelectCustomer(AuthenticatedUser $actor): bool
    {
        return ResolvePricingActorContextService::actorMayPriceAs($actor);
    }

    private function isPortalActor(AuthenticatedUser $actor): bool
    {
        foreach ($actor->roles as $roleCode) {
            if (ProtectedIdentityCatalog::isCustomerFacingRole(RoleCode::fromString($roleCode))) {
                return true;
            }
        }

        return false;
    }
}
