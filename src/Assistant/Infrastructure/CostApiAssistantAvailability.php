<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantAvailability;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingDbcoService;

/**
 * Availability from the Cost API, the same call pricing makes: the company is
 * TriCab USA for the US region and TriCab Australia otherwise (as
 * `ResolvePricingDbcoService` maps currencies), and the length is pricing's
 * probe length, since stocked-or-made does not depend on it.
 *
 * A failed call still counts when it named a status — a stocked code with no
 * default cost is still stocked. With the gateway disabled every call fails
 * without one, so nothing is reported.
 */
final readonly class CostApiAssistantAvailability implements AssistantAvailability
{
    public function __construct(private LegacyCostCalculationGateway $costs)
    {
    }

    public function forCodes(array $codes, ?string $regionCode): array
    {
        $dbco = strtoupper(trim((string) $regionCode)) === 'US'
            ? ResolvePricingDbcoService::DBCO_TCUS
            : ResolvePricingDbcoService::DBCO_TCA;

        $availability = [];
        foreach (array_unique($codes) as $code) {
            if (trim($code) === '') {
                continue;
            }
            $status = $this->costs->resolveCost($code, $dbco, ResolveCustomerSellPriceService::PROBE_LENGTH)->status;
            if ($status === CustomerSellPriceStatus::AVAILABILITY_STOCKED) {
                $availability[$code] = self::STOCK;
            } elseif ($status === CustomerSellPriceStatus::AVAILABILITY_MAKE) {
                $availability[$code] = self::MAKE;
            }
        }

        return $availability;
    }
}
