<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Margin rule lookup isolated from the rest of the app.
 * May read legacy tmktm via a read-only adapter.
 */
interface CustomerPricingMarginRepository
{
    /**
     * @return array{
     *   marginPercent: string,
     *   status: string,
     *   ruleLevel: string,
     *   coreClass: string
     * }|null null = no margin → TBC
     */
    public function resolveCableMargin(
        string $styleCode,
        string $countryCode,
        ?string $territoryCode,
        ?string $marketCode,
        int $coreCount,
        string $conductorCond,
    ): ?array;
}
