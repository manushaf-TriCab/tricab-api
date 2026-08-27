<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Server-only DBCO resolution. Never accept dbco from the browser.
 * TCA/AUD|NZD → 2507; TCUS/USD → 2517; do not enable 2519.
 */
final class ResolvePricingDbcoService
{
    public const DBCO_TCA = '2507';
    public const DBCO_TCUS = '2517';

    public function resolve(CustomerPricingContext $context, ?string $azurePriceListCode): ?string
    {
        $priceList = strtoupper(trim((string) $azurePriceListCode));
        $sell = strtoupper(trim($context->sellCurrency));

        if ($priceList === 'TCA-BASE') {
            return self::DBCO_TCA;
        }
        if ($priceList === 'TCUS-BASE') {
            return self::DBCO_TCUS;
        }
        if (in_array($sell, ['AUD', 'NZD'], true)) {
            return self::DBCO_TCA;
        }
        if ($sell === 'USD') {
            return self::DBCO_TCUS;
        }

        return null;
    }
}
