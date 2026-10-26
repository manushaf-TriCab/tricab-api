<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * Odoo's exchange rates on a day, as AUD per one unit of each currency (the shape `Ap2Settings::$fxRates` and
 * {@see \TriCab\Core\Finance\Ap2\Service\Ap2Fx} take). Odoo stores `rate` as units of the currency per one unit of the
 * company currency (AUD), so AUD per unit is `1 / rate`. A currency with no rate on or before the day is absent.
 */
final readonly class Ap2OdooRates
{
    /**
     * @param array<string, float>  $audPerUnit by ISO code, AUD included at 1.0
     * @param array<string, string> $rateDates  the day each rate is from (`yyyy-MM-dd`)
     */
    public function __construct(
        public string $date,
        public array $audPerUnit,
        public array $rateDates,
    ) {
    }

    /** @return list<string> the asked-for currencies Odoo has no rate for */
    public function missing(): array
    {
        return array_values(array_diff(Ap2OdooGatewayFields::RATE_CURRENCIES, array_keys($this->audPerUnit)));
    }
}
