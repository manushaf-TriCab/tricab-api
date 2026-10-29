<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Commerce\Contract\CartRepository;
use TriCab\Core\Commerce\Contract\VariantAvailabilityRepository;
use TriCab\Core\Commerce\Domain\Entity\CartLine;
use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;
use TriCab\Core\Commerce\Domain\ValueObject\CartLinePublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode;
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

/**
 * Server-authoritative add-to-cart. Client unitPrice is never trusted.
 */
final class AddCartItemService
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly PricingActorContextResolver $actorContext,
        private readonly ResolveCustomerSellPriceService $pricing,
        private readonly VariantAvailabilityRepository $availability,
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
        string $variantPublicId,
        int $quantity,
        int|float|string $length,
        ?string $pricingContextToken = null,
        ?string $purchaseOptionKey = null,
        ?string $clientClaimedUnitPrice = null,
    ): array {
        $context = $this->actorContext->fromAuthenticatedUser($user, $pricingContextToken);
        $clientIgnored = $clientClaimedUnitPrice !== null && $clientClaimedUnitPrice !== '';

        if ($context->authState === CustomerSellPriceStatus::LOGIN_REQUIRED) {
            return [
                'accepted' => false,
                'status' => CustomerSellPriceStatus::LOGIN_REQUIRED,
                'displayMessage' => 'Sign in to add items to cart.',
                'clientPriceTrusted' => false,
                'clientPriceIgnored' => $clientIgnored,
                'cartPersistence' => 'DURABLE',
            ];
        }

        if ($context->authState === CustomerSellPriceStatus::CUSTOMER_REQUIRED || $context->customerPublicId === null) {
            return [
                'accepted' => false,
                'status' => CustomerSellPriceStatus::CUSTOMER_REQUIRED,
                'displayMessage' => 'Select or link a customer account to add items.',
                'clientPriceTrusted' => false,
                'clientPriceIgnored' => $clientIgnored,
                'cartPersistence' => 'DURABLE',
            ];
        }

        if ($quantity < 1) {
            throw InvalidCartValue::for('Quantity', (string) $quantity);
        }
        $lengthNormalized = CartLineMath::normalizeLength($length);

        $resolved = $this->pricing->resolve($variantPublicId, $context, false, null, false, $lengthNormalized);
        $result = $resolved instanceof CustomerVariantSellPriceDiagnostic ? $resolved->customer : $resolved;

        if ($result->status === CustomerSellPriceStatus::LENGTH_REQUIRED) {
            return [
                'accepted' => false,
                'status' => CustomerSellPriceStatus::LENGTH_REQUIRED,
                'displayMessage' => $result->displayMessage ?? 'Enter length to calculate price',
                'variantId' => $result->variantPublicId,
                'clientPriceTrusted' => false,
                'clientPriceIgnored' => $clientIgnored,
                'cartPersistence' => 'DURABLE',
            ];
        }

        if (!in_array($result->status, [CustomerSellPriceStatus::RESOLVED, CustomerSellPriceStatus::TBC], true)) {
            return [
                'accepted' => false,
                'status' => $result->status,
                'displayMessage' => $result->displayMessage,
                'variantId' => $result->variantPublicId,
                'clientPriceTrusted' => false,
                'clientPriceIgnored' => $clientIgnored,
                'cartPersistence' => 'DURABLE',
            ];
        }

        $availability = $this->availability->findAvailability($variantPublicId);
        if ($availability === CustomerSellPriceStatus::AVAILABILITY_UNKNOWN && $result->availability !== '') {
            $availability = $result->availability;
        }

        $isTbc = $result->status === CustomerSellPriceStatus::TBC;
        $unitPrice = $isTbc ? null : $result->unitSellPrice;
        if ($unitPrice !== null && bccomp($unitPrice, '0', 6) <= 0) {
            $isTbc = true;
            $unitPrice = null;
        }

        $currency = strtoupper((string) ($result->currencyCode ?? $context->sellCurrency));
        $pricingStatus = $isTbc ? CustomerSellPriceStatus::TBC : CustomerSellPriceStatus::RESOLVED;
        $marginSource = $isTbc
            ? MarginSource::TBC
            : ($context->overrideMarginPercent !== null && $context->overrideMarginPercent !== ''
                ? MarginSource::STAFF_OVERRIDE
                : MarginSource::TMMKTM);
        $uom = $result->uomCode ?? $result->uomSymbol;
        $overrideFlag = $context->overrideMarginPercent !== null && $context->overrideMarginPercent !== '';
        $fingerprint = CartPricingFingerprint::compute(
            $variantPublicId,
            $context->customerPublicId,
            $context->pricingMode,
            $currency,
            $pricingStatus,
            $unitPrice,
            $uom,
            $availability,
            $purchaseOptionKey,
            $overrideFlag,
        );
        $lineTotal = CartLineMath::lineTotal($unitPrice, $quantity, $lengthNormalized);
        $effectiveCustomerId = $this->customerResolver->resolve($context);
        $pricingMode = CartPricingMode::fromString($context->pricingMode);

        $productVariantId = $this->carts->resolveProductVariantId($variantPublicId);
        if ($productVariantId === null) {
            throw InvalidCartValue::for('ProductVariantId', $variantPublicId);
        }

        $display = $this->carts->resolveVariantDisplay($productVariantId);
        $variantCode = $display['code'] ?? $result->variantCode;
        $displayName = $display['displayName'] ?? null;

        $cart = $this->transaction->run(function () use (
            $user,
            $effectiveCustomerId,
            $pricingMode,
            $currency,
            $productVariantId,
            $variantPublicId,
            $purchaseOptionKey,
            $quantity,
            $lengthNormalized,
            $uom,
            $availability,
            $pricingStatus,
            $unitPrice,
            $lineTotal,
            $marginSource,
            $fingerprint,
            $variantCode,
            $displayName,
        ): \TriCab\Core\Commerce\Domain\Entity\Cart {
            $cart = $this->carts->findOpenCartForActor(
                $user->userId,
                $effectiveCustomerId,
                $pricingMode,
                $currency,
            );
            if ($cart === null) {
                $cart = $this->carts->createCart(
                    $user->userId,
                    $effectiveCustomerId,
                    $pricingMode,
                    $currency,
                    $user->userId,
                );
            }

            $line = new CartLine(
                id: null,
                publicId: CartLinePublicId::generate(),
                cartId: $cart->id(),
                productVariantId: $productVariantId,
                variantPublicId: $variantPublicId,
                purchaseOptionKey: $purchaseOptionKey,
                quantity: $quantity,
                length: $lengthNormalized,
                uomCode: $uom,
                availability: $availability,
                pricingStatus: $pricingStatus,
                unitSellPrice: $unitPrice,
                lineTotal: $lineTotal,
                currencyCode: $currency,
                marginSource: $marginSource,
                pricingFingerprint: $fingerprint,
                resolvedAtUtc: gmdate('c'),
                rowVersion: RowVersionToken::fromBinary(str_repeat("\0", 8)),
                variantCode: $variantCode,
                displayName: $displayName,
            );

            $this->carts->addOrMergeLine($cart->id(), $line, $user->userId);
            $this->carts->touchCartUpdatedAt($cart->id(), $user->userId);

            $reloaded = $this->carts->findOpenCartForActor(
                $user->userId,
                $effectiveCustomerId,
                $pricingMode,
                $currency,
            );

            return $reloaded ?? $cart;
        });

        $includeStaff = $this->isStaffPricingActor($user, $context);
        $dto = $this->assembler->toArray($cart, $context, $user, $includeStaff);
        $dto['accepted'] = true;
        $dto['clientPriceTrusted'] = false;
        $dto['clientPriceIgnored'] = $clientIgnored;
        $dto['cartPersistence'] = 'DURABLE';

        return $dto;
    }

    private function isStaffPricingActor(AuthenticatedUser $user, CustomerPricingContext $context): bool
    {
        return ResolvePricingActorContextService::isStaffPricingActor($user, $context);
    }
}
