<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Server-authoritative cart line prepare.
 * Browser unitPrice / margin / customerId are never trusted as commercial authority.
 * Durable cart persistence is available via POST /api/v1/cart/items (cartPersistence=DURABLE_CART_AVAILABLE).
 */
final class PrepareCartItemPricingService
{
    public const CART_PERSISTENCE = 'DURABLE_CART_AVAILABLE';

    public function __construct(
        private readonly ResolveCustomerSellPriceService $pricing,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function prepare(
        string $variantPublicId,
        CustomerPricingContext $context,
        int $quantity,
        int $length,
        ?string $clientClaimedUnitPrice = null,
    ): array {
        if ($quantity < 1 || $length < 1) {
            return [
                'accepted' => false,
                'status' => CustomerSellPriceStatus::REVIEW,
                'displayMessage' => 'Quantity and length must be at least 1.',
                'canAddToCart' => false,
                'clientPriceTrusted' => false,
                'clientPriceIgnored' => $clientClaimedUnitPrice !== null,
                'cartPersistence' => self::CART_PERSISTENCE,
            ];
        }

        $resolved = $this->pricing->resolve($variantPublicId, $context, false, null, false, (string) $length);
        $result = $resolved instanceof CustomerVariantSellPriceDiagnostic ? $resolved->customer : $resolved;
        $clientIgnored = $clientClaimedUnitPrice !== null && $clientClaimedUnitPrice !== '';

        $payload = [
            'accepted' => false,
            'variantId' => $result->variantPublicId,
            'variantCode' => $result->variantCode,
            'status' => $result->status,
            'availability' => $result->availability,
            'unitPrice' => $result->unitSellPrice,
            'currency' => $result->currencyCode,
            'uom' => $result->uomSymbol ?? $result->uomCode,
            'quantity' => $quantity,
            'length' => $length,
            'lengthUnit' => $result->uomSymbol ?? 'm',
            'totalMetres' => $quantity * $length,
            'estimatedTotal' => null,
            'displayMessage' => $result->displayMessage,
            'canAddToCart' => false,
            'clientPriceTrusted' => false,
            'clientPriceIgnored' => $clientIgnored,
            'pricingMode' => $context->pricingMode,
            'customerId' => $context->customerPublicId,
            'customerCode' => $context->customerCode,
            'customerDisplayName' => $context->customerDisplayName,
            'resolvedAtUtc' => gmdate('c'),
            'snapshot' => null,
            'cartPersistence' => self::CART_PERSISTENCE,
        ];

        if ($result->status === CustomerSellPriceStatus::LENGTH_REQUIRED) {
            $payload['displayMessage'] = $result->displayMessage ?? 'Enter length to calculate price';
            $payload['canAddToCart'] = false;
            $payload['accepted'] = false;

            return $payload;
        }

        if ($result->status === CustomerSellPriceStatus::RESOLVED && $result->unitSellPrice !== null) {
            $total = bcmul($result->unitSellPrice, (string) ($quantity * $length), 2);
            $payload['accepted'] = true;
            $payload['canAddToCart'] = true;
            $payload['estimatedTotal'] = $total;
            $payload['snapshot'] = [
                'unitSellPrice' => $result->unitSellPrice,
                'currency' => $result->currencyCode,
                'uom' => $result->uomSymbol ?? $result->uomCode,
                'quantity' => $quantity,
                'length' => $length,
                'estimatedTotal' => $total,
                'resolvedAtUtc' => $payload['resolvedAtUtc'],
                'pricingMode' => $context->pricingMode,
                'customerId' => $context->customerPublicId,
                'marginSource' => $context->overrideMarginPercent !== null ? 'STAFF_OVERRIDE' : 'TMMKTM',
            ];
        }

        if ($result->status === CustomerSellPriceStatus::TBC) {
            // RFQ-oriented: allow staging without fabricating $0.00
            $payload['accepted'] = true;
            $payload['canAddToCart'] = true;
            $payload['unitPrice'] = null;
            $payload['estimatedTotal'] = null;
            $payload['displayMessage'] = $result->displayMessage ?? 'Price TBC';
            $payload['pricingStatusNote'] = 'QUOTE_REQUIRED';
        }

        if ($payload['unitPrice'] === '0' || $payload['unitPrice'] === '0.00' || $payload['unitPrice'] === '0.000') {
            if ($result->status !== CustomerSellPriceStatus::RESOLVED) {
                $payload['unitPrice'] = null;
                $payload['estimatedTotal'] = null;
            }
        }

        return $payload;
    }
}
