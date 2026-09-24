<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\Service;

use TriCab\Core\PriceLookup\Application\DTO\PriceLookupQuoteResult;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupProductNotFound;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupUnavailable;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;
use TriCab\Core\PriceLookup\Contract\PriceLookupFxRepository;
use TriCab\Core\PriceLookup\Contract\PriceLookupPriceRepository;
use TriCab\Core\PriceLookup\Domain\CostPerMeterFallback;
use TriCab\Core\PriceLookup\Domain\MakeStockStatus;
use TriCab\Core\PriceLookup\Domain\PriceLookupDbcoMapper;
use TriCab\Core\PriceLookup\Domain\PriceLookupMetricRate;
use TriCab\Core\PriceLookup\Domain\PriceLookupMoney;
use TriCab\Core\PriceLookup\Domain\StaffEnteredMarginSellFormula;
use TriCab\Core\PriceLookup\Module;
use TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway;

final class QuotePriceLookupService
{
    public const COST_SOURCE_API = 'COST_API';
    public const COST_SOURCE_LEGACY_FALLBACK = 'LEGACY_FALLBACK';
    public const COST_SOURCE_BASE = 'BASE_COST';

    public function __construct(
        private readonly PriceLookupPriceRepository $prices,
        private readonly PriceLookupFxRepository $fx,
        private readonly LegacyCostCalculationGateway $costApi,
        private readonly PriceLookupDbcoMapper $dbcoMapper = new PriceLookupDbcoMapper(),
        private readonly PriceLookupMetricRate $metricRate = new PriceLookupMetricRate(),
        private readonly PriceLookupMoney $money = new PriceLookupMoney(),
        private readonly StaffEnteredMarginSellFormula $sellFormula = new StaffEnteredMarginSellFormula(),
        private readonly CostPerMeterFallback $fallback = new CostPerMeterFallback(),
        private readonly MakeStockStatus $makeStock = new MakeStockStatus(),
    ) {
    }

    public function execute(
        string $productCode,
        string $company,
        string $currency,
        string $metric,
        ?string $length = null,
        ?string $marginPercent = null,
    ): PriceLookupQuoteResult {
        $companyCode = $this->dbcoMapper->assertKnownCompany($company);
        $dbco = $this->dbcoMapper->toDbco($companyCode);
        $currencyCode = $this->assertCurrency($currency);
        $metricCode = $this->metricRate->assertMetric($metric);
        $code = strtoupper(trim($productCode));
        if ($code === '') {
            throw PriceLookupValidationException::invalidProductCode();
        }

        $lengthProbe = $this->normalizeLengthProbe($length);
        if ($length !== null && trim($length) !== '' && trim($length) !== '0') {
            $this->assertPositiveLength($length);
        }

        $row = $this->prices->findByCode($dbco, $code);
        if ($row === null) {
            throw new PriceLookupProductNotFound($code);
        }

        $crate = $this->resolveFxRate($currencyCode);
        $mrate = $this->metricRate->mrate($metricCode, $dbco);
        $status = $this->makeStock->fromWarehouseStatus($row['warehouseStatus']);
        $baseCostRaw = $row['cost'];
        $setupLength = $row['setupLength'] ?? '';
        $setupPremium = $this->money->roundDisplay($this->fallback->setupPremium($code));

        [$unitCost, $costSource] = $this->resolveUnitCost(
            $code,
            $dbco,
            $status,
            $baseCostRaw,
            $setupLength !== '' ? $setupLength : null,
            $lengthProbe,
            $companyCode,
        );

        $adjusted = $this->money->adjustedCost($unitCost, $crate, $mrate);
        $sellRaw = $this->sellFormula->apply($adjusted, $marginPercent);
        $baseAdjusted = $this->money->adjustedCost($baseCostRaw, $crate, $mrate);

        return new PriceLookupQuoteResult(
            productCode: $code,
            status: $status,
            company: $companyCode,
            currency: $currencyCode,
            metric: $metricCode,
            adjustedCost: $this->money->roundDisplay($adjusted),
            sell: $this->money->roundDisplay($sellRaw),
            baseCost: $this->money->roundDisplay($baseAdjusted),
            setupLength: $setupLength !== '' ? $setupLength : '0',
            setupPremium: $setupPremium,
            lastUpdated: $row['lastUpdated'] ?? '',
            costSource: $costSource,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveUnitCost(
        string $productCode,
        string $dbco,
        string $status,
        string $baseCost,
        ?string $setupLength,
        string $lengthProbe,
        string $company,
    ): array {
        $resolution = $this->costApi->resolveCost($productCode, $dbco, $lengthProbe);
        $effective = $resolution->effectiveCostPerUnit();
        if ($effective !== null) {
            return [$effective, self::COST_SOURCE_API];
        }

        // Prefer calculatedCostPerMeter when success but effectiveCostPerUnit null (edge statuses).
        if ($resolution->success
            && $resolution->calculatedCostPerMeter !== null
            && is_numeric($resolution->calculatedCostPerMeter)
            && bccomp((string) $resolution->calculatedCostPerMeter, '0', 6) > 0
        ) {
            return [(string) $resolution->calculatedCostPerMeter, self::COST_SOURCE_API];
        }

        $userLength = $lengthProbe === '1' ? null : $lengthProbe;
        $fallbackCost = $this->fallback->calculate(
            $productCode,
            $status,
            $baseCost,
            $setupLength,
            $userLength,
        );

        if ($fallbackCost !== null && bccomp($fallbackCost, '0', 6) > 0) {
            error_log('[price-lookup] fallback productCode=' . $productCode . ' company=' . $company . ' source=' . self::COST_SOURCE_LEGACY_FALLBACK);

            return [$fallbackCost, self::COST_SOURCE_LEGACY_FALLBACK];
        }

        if (!is_numeric($baseCost) || bccomp($baseCost, '0', 6) <= 0) {
            throw new PriceLookupUnavailable('PRICE_UNAVAILABLE', 'Unable to calculate cost for this product.');
        }

        error_log('[price-lookup] fallback productCode=' . $productCode . ' company=' . $company . ' source=' . self::COST_SOURCE_BASE);

        return [$baseCost, self::COST_SOURCE_BASE];
    }

    private function resolveFxRate(string $currency): string
    {
        $rate = $this->fx->findLatestRate($currency);
        if ($rate !== null && is_numeric($rate) && bccomp($rate, '0', 12) > 0) {
            return $rate;
        }

        if ($currency === 'AUD') {
            return '1';
        }

        throw new PriceLookupUnavailable('PRICE_UNAVAILABLE', 'FX rate unavailable for ' . $currency . '.');
    }

    private function assertCurrency(string $currency): string
    {
        $code = strtoupper(trim($currency));
        if (!in_array($code, Module::CURRENCIES, true)) {
            throw PriceLookupValidationException::invalidCurrency($currency);
        }

        return $code;
    }

    private function normalizeLengthProbe(?string $length): string
    {
        if ($length === null) {
            return '1';
        }
        $trimmed = trim($length);
        if ($trimmed === '' || $trimmed === '0') {
            return '1';
        }

        return $trimmed;
    }

    private function assertPositiveLength(string $length): void
    {
        $trimmed = trim($length);
        if (!is_numeric($trimmed) || bccomp($trimmed, '0', 6) <= 0) {
            throw PriceLookupValidationException::invalidLength($length);
        }
    }
}
