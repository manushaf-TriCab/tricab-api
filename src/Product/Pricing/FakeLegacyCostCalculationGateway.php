<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Pricing;

use TriCab\Core\Product\Application\Pricing\CostResolution;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway;

/** Seedable Cost API double for unit tests. */
final class FakeLegacyCostCalculationGateway implements LegacyCostCalculationGateway
{
    /** @var array<string, CostResolution> */
    private array $map = [];

    /** @var list<array{productCode: string, dbco: string, requestedLength: string}> */
    public array $calls = [];

    public function seed(string $productCode, string $dbco, string $requestedLength, CostResolution $resolution): void
    {
        $this->map[$this->key($productCode, $dbco, $requestedLength)] = $resolution;
    }

    public function resolveCost(string $productCode, string $dbco, string $requestedLength): CostResolution
    {
        $this->calls[] = [
            'productCode' => $productCode,
            'dbco' => $dbco,
            'requestedLength' => $requestedLength,
        ];

        $key = $this->key($productCode, $dbco, $requestedLength);
        if (isset($this->map[$key])) {
            return $this->map[$key];
        }

        return CostResolution::failure(
            $productCode,
            $dbco,
            'FAKE_NOT_SEEDED',
            CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
            $requestedLength,
        );
    }

    private function key(string $productCode, string $dbco, string $requestedLength): string
    {
        return strtoupper(trim($productCode)) . '|' . trim($dbco) . '|' . trim($requestedLength);
    }
}
