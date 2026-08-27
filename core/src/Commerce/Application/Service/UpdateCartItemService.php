<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Commerce\Contract\CartRepository;
use TriCab\Core\Commerce\Contract\VariantAvailabilityRepository;
use TriCab\Core\Commerce\Domain\Exception\CartAccessDenied;
use TriCab\Core\Commerce\Domain\Exception\CartNotFound;
use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;
use TriCab\Core\Commerce\Domain\ValueObject\CartLinePublicId;
use TriCab\Core\Commerce\Domain\ValueObject\MarginSource;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\PricingActorContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class UpdateCartItemService
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly PricingActorContextResolver $actorContext,
        private readonly ResolveCustomerSellPriceService $pricing,
        private readonly VariantAvailabilityRepository $availability,
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
        ?int $quantity,
        int|float|string|null $length,
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
        if ($existing === null) {
            throw CartNotFound::forLinePublicId($linePublicId);
        }
        if ($existing->cartId() === null) {
            throw CartNotFound::forLinePublicId($linePublicId);
        }

        $newQty = $quantity ?? $existing->quantity();
        if ($newQty < 1) {
            throw InvalidCartValue::for('Quantity', (string) $newQty);
        }
        $newLength = $length !== null
            ? CartLineMath::normalizeLength($length)
            : $existing->length();
        if ($newLength === null) {
            throw InvalidCartValue::for('Length', '');
        }

        $resolved = $this->pricing->resolve($existing->variantPublicId(), $context, false, null, false, $newLength);
        $result = $resolved instanceof CustomerVariantSellPriceDiagnostic ? $resolved->customer : $resolved;

        $isTbc = $result->status === CustomerSellPriceStatus::TBC
            || $result->unitSellPrice === null
            || ($result->unitSellPrice !== null && bccomp($result->unitSellPrice, '0', 6) <= 0);
        $unitPrice = $isTbc ? null : $result->unitSellPrice;
        $pricingStatus = $isTbc ? CustomerSellPriceStatus::TBC : CustomerSellPriceStatus::RESOLVED;
        $currency = strtoupper((string) ($result->currencyCode ?? $existing->currencyCode()));
        $marginSource = $isTbc
            ? MarginSource::TBC
            : ($context->overrideMarginPercent !== null && $context->overrideMarginPercent !== ''
                ? MarginSource::STAFF_OVERRIDE
                : MarginSource::TMMKTM);
        $availability = $this->availability->findAvailability($existing->variantPublicId());
        if ($availability === CustomerSellPriceStatus::AVAILABILITY_UNKNOWN && $result->availability !== '') {
            $availability = $result->availability;
        }
        $uom = $result->uomCode ?? $result->uomSymbol ?? $existing->uomCode();
        $fingerprint = CartPricingFingerprint::compute(
            $existing->variantPublicId(),
            $context->customerPublicId,
            $context->pricingMode,
            $currency,
            $pricingStatus,
            $unitPrice,
            $uom,
            $availability,
            $existing->purchaseOptionKey(),
            $context->overrideMarginPercent !== null && $context->overrideMarginPercent !== '',
        );
        $lineTotal = CartLineMath::lineTotal($unitPrice, $newQty, $newLength);

        $updatedLine = $existing->withPricing(
            pricingStatus: $pricingStatus,
            unitSellPrice: $unitPrice,
            lineTotal: $lineTotal,
            currencyCode: $currency,
            marginSource: $marginSource,
            pricingFingerprint: $fingerprint,
            resolvedAtUtc: gmdate('c'),
            uomCode: $uom,
            availability: $availability,
            length: $newLength,
            quantity: $newQty,
        );

        $cart = $this->transaction->run(function () use ($user, $existing, $lineId, $updatedLine, $expected, $context) {
            $this->carts->updateLine($existing->cartId(), $lineId, $updatedLine, $expected, $user->userId);
            $this->carts->touchCartUpdatedAt($existing->cartId(), $user->userId);

            return $this->reloadCart($user, $context);
        });

        return $this->assembler->toArray(
            $cart,
            $context,
            $user,
            $this->isStaff($user, $context),
        );
    }

    private function reloadCart(AuthenticatedUser $user, CustomerPricingContext $context): \TriCab\Core\Commerce\Domain\Entity\Cart
    {
        $currency = strtoupper($context->sellCurrency !== '' ? $context->sellCurrency : 'AUD');
        $cart = $this->carts->findOpenCartForActor(
            $user->userId,
            $this->customerResolver->resolve($context),
            \TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode::fromString($context->pricingMode),
            $currency,
        );
        if ($cart === null) {
            throw CartAccessDenied::notOwner();
        }

        return $cart;
    }

    private function isStaff(AuthenticatedUser $user, CustomerPricingContext $context): bool
    {
        if ($context->pricingMode !== CustomerPricingContext::MODE_STAFF_SELECTED) {
            return false;
        }

        return in_array(ResolvePricingActorContextService::PERM_PRICE_AS, $user->permissions, true)
            || in_array(ResolvePricingActorContextService::PERM_CUSTOMER_READ, $user->permissions, true)
            || in_array(ResolvePricingActorContextService::PERM_MARGIN_OVERRIDE, $user->permissions, true)
            || in_array(ResolvePricingActorContextService::PERM_PRICING_MANAGE, $user->permissions, true);
    }
}
