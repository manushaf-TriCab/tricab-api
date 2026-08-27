<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Server-authoritative RFQ line pricing preparation.
 * Browser unitPrice is never trusted.
 */
final class PrepareRfqLinePricingService
{
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
                'canAddToRfq' => false,
                'clientPriceTrusted' => false,
                'clientPriceIgnored' => $clientClaimedUnitPrice !== null,
            ];
        }

        $resolved = $this->pricing->resolve($variantPublicId, $context, false, null, false, (string) $length);
        $result = $resolved instanceof CustomerVariantSellPriceDiagnostic ? $resolved->customer : $resolved;

        $clientIgnored = $clientClaimedUnitPrice !== null && $clientClaimedUnitPrice !== '';
        $payload = [
            'accepted' => $result->status === CustomerSellPriceStatus::RESOLVED && $result->canAddToRfq,
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
            'canAddToRfq' => $result->canAddToRfq,
            'clientPriceTrusted' => false,
            'clientPriceIgnored' => $clientIgnored,
            'resolvedAtUtc' => gmdate('c'),
            'snapshot' => null,
            'rfqPersistence' => 'RFQ_SNAPSHOT_SCHEMA_REVIEW',
        ];

        if ($result->status === CustomerSellPriceStatus::RESOLVED && $result->unitSellPrice !== null) {
            $total = bcmul($result->unitSellPrice, (string) ($quantity * $length), 2);
            $payload['estimatedTotal'] = $total;
            $payload['snapshot'] = [
                'unitSellPrice' => $result->unitSellPrice,
                'currency' => $result->currencyCode,
                'uom' => $result->uomSymbol ?? $result->uomCode,
                'quantity' => $quantity,
                'length' => $length,
                'estimatedTotal' => $total,
                'resolvedAtUtc' => $payload['resolvedAtUtc'],
            ];
        }

        if ($result->status === CustomerSellPriceStatus::TBC) {
            $payload['accepted'] = false;
            $payload['canAddToRfq'] = false;
            $payload['displayMessage'] = $result->displayMessage ?? 'Price TBC';
            $payload['unitPrice'] = null;
            $payload['estimatedTotal'] = null;
        }

        // Never convert unresolved to 0.00
        if ($payload['unitPrice'] === '0' || $payload['unitPrice'] === '0.00' || $payload['unitPrice'] === '0.000') {
            if ($result->status !== CustomerSellPriceStatus::RESOLVED) {
                $payload['unitPrice'] = null;
                $payload['estimatedTotal'] = null;
            }
        }

        return $payload;
    }
}
