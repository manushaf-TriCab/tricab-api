<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Commerce\Contract\CartRepository;
use TriCab\Core\Commerce\Domain\Exception\CartNotFound;
use TriCab\Core\Commerce\Domain\ValueObject\CartLinePublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\PricingActorContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class RemoveCartItemService
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly PricingActorContextResolver $actorContext,
        private readonly CartDtoAssembler $assembler,
        private readonly CartEffectiveCustomerResolver $customerResolver,
        private readonly ApplicationTransaction $transaction,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        AuthenticatedUser $user,
        string $linePublicId,
        string $expectedRowVersion,
        ?string $pricingContextToken = null,
    ): array {
        $context = $this->actorContext->fromAuthenticatedUser($user, $pricingContextToken);
        if ($context->authState === CustomerSellPriceStatus::LOGIN_REQUIRED) {
            return [
                'accepted' => false,
                'status' => CustomerSellPriceStatus::LOGIN_REQUIRED,
                'displayMessage' => 'Sign in to update cart.',
            ];
        }

        $lineId = CartLinePublicId::fromString($linePublicId);
        $expected = RowVersionToken::fromBase64($expectedRowVersion);
        $existing = $this->carts->findLineByPublicId($lineId, $user->userId);
        if ($existing === null || $existing->cartId() === null) {
            throw CartNotFound::forLinePublicId($linePublicId);
        }

        $cart = $this->transaction->run(function () use ($user, $existing, $lineId, $expected, $context) {
            $this->carts->softRemoveLine($existing->cartId(), $lineId, $expected, $user->userId);
            $this->carts->touchCartUpdatedAt($existing->cartId(), $user->userId);

            $currency = strtoupper($context->sellCurrency !== '' ? $context->sellCurrency : 'AUD');

            return $this->carts->findOpenCartForActor(
                $user->userId,
                $this->customerResolver->resolve($context),
                CartPricingMode::fromString($context->pricingMode),
                $currency,
            );
        });

        if ($cart === null) {
            throw CartNotFound::forLinePublicId($linePublicId);
        }

        $includeStaff = ResolvePricingActorContextService::isStaffPricingActor($user, $context);

        return $this->assembler->toArray($cart, $context, $user, $includeStaff);
    }
}
