<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

/**
 * Deterministic pricing fingerprint for cart line freshness checks.
 */
final class CartPricingFingerprint
{
    public static function compute(
        string $variantPublicId,
        ?string $customerPublicId,
        string $pricingMode,
        string $currency,
        string $pricingStatus,
        ?string $unitSellPrice,
        ?string $uom,
        ?string $availability,
        ?string $purchaseOptionKey,
        bool $overrideFlag,
    ): string {
        $payload = implode('|', [
            $variantPublicId,
            $customerPublicId ?? '',
            $pricingMode,
            strtoupper($currency),
            $pricingStatus,
            $unitSellPrice ?? '',
            $uom ?? '',
            $availability ?? '',
            $purchaseOptionKey ?? '',
            $overrideFlag ? '1' : '0',
        ]);

        return hash('sha256', $payload);
    }
}
