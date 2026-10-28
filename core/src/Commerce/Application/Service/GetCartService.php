<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Commerce\Contract\CartRepository;
use TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\PricingActorContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Get or create an empty OPEN cart for the actor's pricing context.
 */
final class GetCartService
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly PricingActorContextResolver $actorContext,
        private readonly CartEffectiveCustomerResolver $customerResolver,
        private readonly CartDtoAssembler $assembler,
        private readonly ApplicationTransaction $transaction,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        AuthenticatedUser $user,
        ?string $pricingContextToken = null,
    ): array {
        $context = $this->actorContext->fromAuthenticatedUser($user, $pricingContextToken);

        if ($context->authState === CustomerSellPriceStatus::LOGIN_REQUIRED) {
            return [
                'accepted' => false,
                'status' => CustomerSellPriceStatus::LOGIN_REQUIRED,
                'displayMessage' => 'Sign in to view your cart.',
            ];
        }

        $currency = strtoupper($context->sellCurrency !== '' ? $context->sellCurrency : 'AUD');
        $pricingMode = CartPricingMode::fromString($context->pricingMode);
        $effectiveCustomerId = $this->customerResolver->resolve($context);

        $cart = $this->transaction->run(function () use ($user, $effectiveCustomerId, $pricingMode, $currency): \TriCab\Core\Commerce\Domain\Entity\Cart {
            $existing = $this->carts->findOpenCartForActor(
                $user->userId,
                $effectiveCustomerId,
                $pricingMode,
                $currency,
            );
            if ($existing !== null) {
                return $existing;
            }

            return $this->carts->createCart(
                $user->userId,
                $effectiveCustomerId,
                $pricingMode,
                $currency,
                $user->userId,
            );
        });

        $includeStaff = $this->isStaffPricingActor($user, $context);

        return $this->assembler->toArray($cart, $context, $user, $includeStaff);
    }

    private function isStaffPricingActor(AuthenticatedUser $user, CustomerPricingContext $context): bool
    {
        return ResolvePricingActorContextService::isStaffPricingActor($user, $context);
    }
}
