<?php

declare(strict_types=1);

namespace TriCab\Api\PriceLookup\Persistence;

use TriCab\Core\PriceLookup\Contract\PriceLookupFxRepository;

final class InMemoryPriceLookupFxRepository implements PriceLookupFxRepository
{
    /** @param array<string, string> $rates */
    public function __construct(
        private array $rates = [
            'AUD' => '1',
            'USD' => '0.65',
            'NZD' => '1.08',
            'SGD' => '0.88',
            'BHT' => '23.5',
            'MYR' => '3.1',
        ],
    ) {
    }

    public function seed(string $currency, string $rate): void
    {
        $this->rates[strtoupper($currency)] = $rate;
    }

    public function findLatestRate(string $currencyCode): ?string
    {
        $code = strtoupper(trim($currencyCode));

        return $this->rates[$code] ?? null;
    }
}
