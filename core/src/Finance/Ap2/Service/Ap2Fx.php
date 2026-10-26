<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

/**
 * Converts to AUD for the dashboard, ageing and supplier balances. Rates come from the settings table until Phase 9
 * reads Odoo's; a currency with no rate is counted at face value and reported in {@see self::missing()} so the
 * page can say the total is approximate rather than silently wrong.
 */
final class Ap2Fx
{
    /** @var array<string, true> */
    private array $missing = [];

    /**
     * @param array<string, float> $rates AUD per one unit of the currency
     */
    public function __construct(private readonly array $rates)
    {
    }

    public function toAud(float $amount, string $currency): float
    {
        if ($currency === 'AUD') {
            return $amount;
        }
        $rate = $this->rates[$currency] ?? null;
        if ($rate === null || $rate <= 0.0) {
            $this->missing[$currency] = true;

            return $amount;
        }

        return round($amount * $rate, 2);
    }

    /** @return list<string> the currencies counted at face value, sorted */
    public function missing(): array
    {
        $codes = array_keys($this->missing);
        sort($codes);

        return $codes;
    }
}
