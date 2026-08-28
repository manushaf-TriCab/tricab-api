<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Commerce\Application\DTO\CartLineMedia;
use TriCab\Core\Commerce\Contract\CartLineMediaResolver;
use TriCab\Core\Commerce\Domain\Entity\Cart;
use TriCab\Core\Commerce\Domain\Entity\CartLine;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;

/**
 * Assembles customer-safe cart DTOs. Never includes baseCost / priceList / margin rule ids.
 */
final class CartDtoAssembler
{
    public function __construct(
        private readonly ?CartLineMediaResolver $mediaResolver = null,
    ) {
    }

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

        $mediaByVariant = $this->resolveMedia($cart);
        foreach ($cart->lines() as $line) {
            $items[] = $this->lineToArray($line, media: $this->mediaFor($line->variantPublicId(), $mediaByVariant));
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
    public function lineToArray(CartLine $line, ?bool $priceChanged = null, ?CartLineMedia $media = null): array
    {
        $variantPublicId = $line->variantPublicId();
        $row = [
            'lineId' => $line->publicId()->toString(),
            'variantId' => $variantPublicId,
            'variantPublicId' => $variantPublicId,
            'productPublicId' => $media?->productPublicId,
            'variantCode' => $line->variantCode(),
            'displayName' => $line->displayName(),
            'imageUrl' => $media?->imageUrl,
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

    /**
     * @return array<string, CartLineMedia>
     */
    private function resolveMedia(Cart $cart): array
    {
        if ($this->mediaResolver === null) {
            return [];
        }

        $variantPublicIds = [];
        foreach ($cart->lines() as $line) {
            $variantPublicIds[] = $line->variantPublicId();
        }

        return $this->mediaResolver->resolveForVariantPublicIds($variantPublicIds);
    }

    /**
     * @param array<string, CartLineMedia> $mediaByVariant
     */
    private function mediaFor(string $variantPublicId, array $mediaByVariant): ?CartLineMedia
    {
        if (isset($mediaByVariant[$variantPublicId])) {
            return $mediaByVariant[$variantPublicId];
        }

        $upper = strtoupper($variantPublicId);
        foreach ($mediaByVariant as $key => $media) {
            if (strtoupper((string) $key) === $upper) {
                return $media;
            }
        }

        return null;
    }
}
