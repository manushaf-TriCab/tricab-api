<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

use TriCab\Core\Product\Migration\Pricing\LegacyCyrteMappingContract;

final class InMemoryCurrencyFxRateRepository implements CurrencyFxRateRepository
{
    /** @param array<string, array{rate: string, source: string, cyrteId: int}> $byCurrency */
    public function __construct(private array $byCurrency = [])
    {
        if ($this->byCurrency === []) {
            $this->byCurrency = [
                'AUD' => ['rate' => '1', 'source' => 'cyrte:' . LegacyCyrteMappingContract::AUD, 'cyrteId' => LegacyCyrteMappingContract::AUD],
                'USD' => ['rate' => '1.4225', 'source' => 'cyrte:' . LegacyCyrteMappingContract::USD, 'cyrteId' => LegacyCyrteMappingContract::USD],
                'NZD' => ['rate' => '1.08', 'source' => 'cyrte:' . LegacyCyrteMappingContract::NZD, 'cyrteId' => LegacyCyrteMappingContract::NZD],
            ];
        }
    }

    public function findLatestRate(string $sellCurrencyCode): ?array
    {
        return $this->byCurrency[strtoupper($sellCurrencyCode)] ?? null;
    }
}
