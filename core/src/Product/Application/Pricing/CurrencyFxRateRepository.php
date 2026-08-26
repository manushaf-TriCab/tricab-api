<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

interface CurrencyFxRateRepository
{
    /**
     * Latest FX divisor for sell currency (legacy: baseCost / cyrte).
     *
     * @return array{rate: string, source: string, cyrteId: int}|null
     */
    public function findLatestRate(string $sellCurrencyCode): ?array;
}
