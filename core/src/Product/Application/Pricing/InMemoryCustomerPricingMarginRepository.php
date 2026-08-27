<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/** Fixture margin repository for unit tests. */
final class InMemoryCustomerPricingMarginRepository implements CustomerPricingMarginRepository
{
    /** @param array<string, array{marginPercent: string, status: string, ruleLevel: string, coreClass: string}> $byKey */
    public function __construct(private array $byKey = [])
    {
    }

    public function seed(
        string $styleCode,
        string $countryCode,
        ?string $territoryCode,
        ?string $marketCode,
        int $coreCount,
        array $result,
    ): void {
        $this->byKey[$this->key($styleCode, $countryCode, $territoryCode, $marketCode, $coreCount)] = $result;
    }

    public function resolveCableMargin(
        string $styleCode,
        string $countryCode,
        ?string $territoryCode,
        ?string $marketCode,
        int $coreCount,
        string $conductorCond,
    ): ?array {
        unset($conductorCond);

        return $this->byKey[$this->key($styleCode, $countryCode, $territoryCode, $marketCode, $coreCount)] ?? null;
    }

    private function key(
        string $styleCode,
        string $countryCode,
        ?string $territoryCode,
        ?string $marketCode,
        int $coreCount,
    ): string {
        return strtoupper($styleCode) . '|' . strtoupper($countryCode) . '|' . strtoupper((string) $territoryCode)
            . '|' . strtoupper((string) $marketCode) . '|' . $coreCount;
    }
}
