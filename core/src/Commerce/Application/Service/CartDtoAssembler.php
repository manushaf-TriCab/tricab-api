<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Commerce\Domain\Entity\Cart;
use TriCab\Core\Commerce\Domain\Entity\CartLine;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;

/**
 * Assembles customer-safe cart DTOs. Never includes baseCost / priceList / margin rule ids.
 */
final class CartDtoAssembler
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(
        Cart $cart,
        CustomerPricingContext $context,
        AuthenticatedUser $user,
        bool $includeStaffFields = false,
    ): array {
        $items = [];
        $subtotal = '0.00';
        $hasUnpriced = false;
        $freshCount = 0;
        $staleCount = 0;

        foreach ($cart->lines() as $line) {
            $items[] = $this->lineToArray($line);
            if ($line->lineTotal() === null) {
                $hasUnpriced = true;
            } else {
                $subtotal = bcadd($subtotal, $line->lineTotal(), 2);
            }
        }

        $pricingFreshness = 'UNKNOWN';
        if ($items === []) {
            $pricingFreshness = 'FRESH';
        }

        $effectiveCustomer = null;
        if ($context->customerPublicId !== null) {
            $effectiveCustomer = [
                'id' => $context->customerPublicId,
                'code' => $context->customerCode,
                'displayName' => $context->customerDisplayName,
            ];
        }

        $dto = [
            'cartId' => $cart->publicId()->toString(),
            'status' => $cart->status()->toString(),
            'currency' => $cart->currencyCode(),
            'pricingMode' => $cart->pricingMode()->toString(),
            'effectiveCustomer' => $effectiveCustomer,
            'itemCount' => count($items),
            'subtotal' => $hasUnpriced ? null : $subtotal,
            'hasUnpricedLines' => $hasUnpriced,
            'pricingFreshness' => $pricingFreshness,
            'items' => $items,
        ];

        if ($includeStaffFields && $context->overrideMarginPercent !== null) {
            $dto['marginOverrideActive'] = true;
            $dto['marginOverridePercent'] = $context->overrideMarginPercent;
        } elseif ($includeStaffFields) {
            $dto['marginOverrideActive'] = false;
        }

        unset($user, $freshCount, $staleCount);

        return $dto;
    }

    /**
     * @return array<string, mixed>
     */
    public function lineToArray(CartLine $line, ?bool $priceChanged = null): array
    {
        $row = [
            'lineId' => $line->publicId()->toString(),
            'variantId' => $line->variantPublicId(),
            'variantCode' => $line->variantCode(),
            'displayName' => $line->displayName(),
            'availability' => $line->availability(),
            'quantity' => $line->quantity(),
            'length' => $line->length(),
            'uom' => $line->uomCode(),
            'pricingStatus' => $line->pricingStatus(),
            'unitPrice' => $line->unitSellPrice(),
            'lineTotal' => $line->lineTotal(),
            'currency' => $line->currencyCode(),
            'rowVersion' => $line->rowVersion()->toBase64(),
        ];
        if ($priceChanged !== null) {
            $row['priceChanged'] = $priceChanged;
        }

        return $row;
    }
}
