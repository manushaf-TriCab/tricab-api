<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Pricing\FakeLegacyCostCalculationGateway;
use TriCab\Core\Product\Application\Pricing\CostResolution;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryVariantCommercialCatalogRepository;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;

/** Phase 13.7G — Legacy Cost API as primary runtime cost source for all variants. */
final class Phase137gRuntimeCostApiTest extends TestCase
{
    public function test_bva_with_azure_base_uses_legacy_cost_api_in_primary_mode(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-PAXA/5C50BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PAXA/5C50BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: null,
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '12.291',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $svc = $this->service($fake);
        $diag = $svc->resolve('v-bva', $this->ctx(), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $diag);
        self::assertContains('PRICING_COST_SOURCE_MODE:legacy-cost-api', $diag->trace);
        self::assertContains('COST_SOURCE:LEGACY_COST_API', $diag->trace);
        self::assertContains('BASE_META_FROM_CATALOG:TCA-BASE', $diag->trace);
        self::assertNotContains('BASE_COST_FROM_AZURE:TCA-BASE', $diag->trace);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $diag->customer->status);
    }

    public function test_non_bva_without_azure_base_calls_legacy_cost_api(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('KN-PAXN/1C500OR', '2507', '100', new CostResolution(
            productCode: 'KN-PAXN/1C500OR',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: null,
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '50.000',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seed('v-kn', [
            'variantPublicId' => 'v-kn',
            'variantCode' => 'KN-PAXN/1C500OR',
            'sku' => null,
            'styleCode' => 'KN',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'KN-PAXN/1C500OR',
            'azureBase' => null,
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('KN', 'AU', 'VIC', 'ELE', 1, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'sdi',
        ]);

        $svc = new ResolveCustomerSellPriceService(
            $catalog,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
            pricingCostSource: 'legacy-cost-api',
        );

        $diag = $svc->resolve('v-kn', $this->ctx(), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $diag);
        self::assertContains('COST_SOURCE:LEGACY_COST_API', $diag->trace);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $diag->customer->status);
    }

    public function test_stocked_buy_cost_resolves_in_primary_mode(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-STOCK/1C1BK', '2507', '100', new CostResolution(
            productCode: 'BVA-STOCK/1C1BK',
            dbco: '2507',
            status: 'STOCKED',
            defaultCost: '40',
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: null,
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $result = $this->resolveDiagnostic('v1', 'BVA-STOCK/1C1BK', '99', $fake, '100');
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->customer->status);
        self::assertSame(CustomerSellPriceStatus::AVAILABILITY_STOCKED, $result->customer->availability);
        self::assertContains('COST_SOURCE:LEGACY_COST_API', $result->trace);
    }

    public function test_length_propagates_to_cost_api(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-PAXA/5C50BK', '2507', '10', new CostResolution(
            productCode: 'BVA-PAXA/5C50BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: null,
            defaultLength: null,
            requestedLength: '10',
            calculatedCostPerMeter: '10.000',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));
        $fake->seed('BVA-PAXA/5C50BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PAXA/5C50BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: null,
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '12.291',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $svc = $this->service($fake);
        $short = $svc->resolve('v-bva', $this->ctx(), true, null, false, '10');
        $long = $svc->resolve('v-bva', $this->ctx(), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $short);
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $long);
        self::assertNotSame($short->customer->unitSellPrice, $long->customer->unitSellPrice);
        self::assertSame('10', $fake->calls[0]['requestedLength']);
        self::assertSame('100', $fake->calls[1]['requestedLength']);
    }

    public function test_cost_api_failure_is_temporarily_unavailable_not_azure_fallback(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-STOCK/1C1BK', '2507', '100', CostResolution::failure(
            'BVA-STOCK/1C1BK',
            '2507',
            'TIMEOUT',
            CustomerSellPriceStatus::AVAILABILITY_STOCKED,
            '100',
        ));

        $result = $this->resolveDiagnostic('v1', 'BVA-STOCK/1C1BK', '40', $fake, '100');
        self::assertSame(CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE, $result->customer->status);
        self::assertNotContains('COST_API_FAILED_AZURE_STOCKED_FALLBACK', $result->trace);
    }

    public function test_missing_automatic_margin_is_tbc_not_unavailable(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('KN-PAXN/1C500OR', '2507', '100', new CostResolution(
            productCode: 'KN-PAXN/1C500OR',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: null,
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '50.000',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seed('v-kn', [
            'variantPublicId' => 'v-kn',
            'variantCode' => 'KN-PAXN/1C500OR',
            'sku' => null,
            'styleCode' => 'KN',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'KN-PAXN/1C500OR',
            'azureBase' => null,
        ]);

        $svc = new ResolveCustomerSellPriceService(
            $catalog,
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
            pricingCostSource: 'legacy-cost-api',
        );

        $result = $svc->resolve('v-kn', $this->ctx(), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $result);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->customer->status);
        self::assertSame('0', $result->marginPercent);
        self::assertContains('NO_MARGIN_RULE', $result->trace);
        self::assertContains('MARGIN_DEFAULT:0', $result->trace);
        self::assertContains('COST_SOURCE:LEGACY_COST_API', $result->trace);
    }

    public function test_staff_override_with_cost_api_cost_resolves(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-PAXA/5C50BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PAXA/5C50BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: null,
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '12.291',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $svc = $this->service($fake);
        $result = $svc->resolve('v-bva', $this->ctx()->withOverrideMargin('31'), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $result);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->customer->status);
        self::assertSame('31', $result->overrideMarginPercent);
    }

    public function test_batch_isolation_mixed_statuses(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-PAXA/5C50BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PAXA/5C50BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: null,
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '12.291',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));
        $fake->seed('BVA-FAIL/1C1BK', '2507', '100', CostResolution::failure(
            'BVA-FAIL/1C1BK',
            '2507',
            'TIMEOUT',
            CustomerSellPriceStatus::AVAILABILITY_MAKE,
            '100',
        ));

        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seedFromLegacyBase('v-ok', [
            'baseCost' => '99',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-PAXA/5C50BK',
            'styleCode' => 'BVA',
            'availability' => 'MAKE',
        ]);
        $catalog->seedFromLegacyBase('v-fail', [
            'baseCost' => '99',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-FAIL/1C1BK',
            'styleCode' => 'BVA',
            'availability' => 'MAKE',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'ELE', 5, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'sdi',
        ]);

        $svc = new ResolveCustomerSellPriceService(
            $catalog,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
            pricingCostSource: 'legacy-cost-api',
        );

        $batch = $svc->resolveBatch(['v-ok', 'v-fail'], $this->ctx(), null, '100');
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $batch[0]->status);
        self::assertSame(CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE, $batch[1]->status);
    }

    private function ctx(): CustomerPricingContext
    {
        return new CustomerPricingContext('cust-1', 'AU', 'VIC', 'ELE', 'AUD', 'OK');
    }

    private function service(FakeLegacyCostCalculationGateway $fake): ResolveCustomerSellPriceService
    {
        $base = new InMemoryVariantCommercialCatalogRepository();
        $base->seedFromLegacyBase('v-bva', [
            'baseCost' => '100.000',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-PAXA/5C50BK',
            'styleCode' => 'BVA',
            'availability' => 'MAKE',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'ELE', 5, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'sdi',
        ]);

        return new ResolveCustomerSellPriceService(
            $base,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
            pricingCostSource: 'legacy-cost-api',
        );
    }

    private function resolveDiagnostic(
        string $variantId,
        string $variantCode,
        string $azureBaseCost,
        FakeLegacyCostCalculationGateway $fake,
        string $length,
    ): CustomerVariantSellPriceDiagnostic {
        $base = new InMemoryVariantCommercialCatalogRepository();
        $base->seedFromLegacyBase($variantId, [
            'baseCost' => $azureBaseCost,
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => $variantCode,
            'styleCode' => 'BVA',
            'availability' => 'STOCKED',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'ELE', 1, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'sdi',
        ]);

        $svc = new ResolveCustomerSellPriceService(
            $base,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
            pricingCostSource: 'legacy-cost-api',
        );

        $result = $svc->resolve($variantId, $this->ctx(), true, null, false, $length);
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $result);

        return $result;
    }
}
