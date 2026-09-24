<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;
use TriCab\Core\PriceLookup\Module;

/**
 * Legacy metre/foot conversion factor (mrate) from template-price-lookup.php.
 *
 * metre + TCUS(2517) → 1/3.2808399
 * metre + other → 1
 * foot + TCUS(2517) → 1
 * foot + other → 3.2808399
 */
final class PriceLookupMetricRate
{
    private const FEET_PER_METRE = '3.2808399';

    public function assertMetric(string $metric): string
    {
        $normalized = strtolower(trim($metric));
        if (!in_array($normalized, Module::METRICS, true)) {
            throw PriceLookupValidationException::invalidMetric($metric);
        }

        return $normalized;
    }

    public function mrate(string $metric, string $dbco): string
    {
        $metric = $this->assertMetric($metric);
        $isTcus = trim($dbco) === PriceLookupDbcoMapper::DBCO_TCUS;

        if ($metric === 'metre') {
            return $isTcus ? bcdiv('1', self::FEET_PER_METRE, 12) : '1';
        }

        return $isTcus ? '1' : self::FEET_PER_METRE;
    }
}
