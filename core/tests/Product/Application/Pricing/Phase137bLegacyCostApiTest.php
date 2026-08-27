<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Pricing\DisabledLegacyCostCalculationGateway;
use TriCab\Api\Product\Pricing\FakeLegacyCostCalculationGateway;
use TriCab\Api\Product\Pricing\HttpLegacyCostCalculationGateway;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Product\Application\Pricing\CostResolution;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryVariantCommercialCatalogRepository;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingDbcoService;

final class Phase137bLegacyCostApiTest extends TestCase
{
    public function test_make_with_length_uses_cost_api_effective_cost(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('KN-PAXN/1C500OR', '2507', '170', new CostResolution(
            productCode: 'KN-PAXN/1C500OR',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '999',
            defaultLength: null,
            requestedLength: '170',
            calculatedCostPerMeter: '113.291',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $svc = $this->service($fake, true, 'KN-PAXN/1C500OR', 'MAKE', '1');
        $result = $svc->resolve('v1', $this->ctx(), false, null, false, '170');

        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->status);
        // sell = (113.291/1)*100/(100-50) = 226.582
        self::assertSame('226.582', $result->unitSellPrice);
        self::assertSame(CustomerSellPriceStatus::AVAILABILITY_MAKE, $result->availability);

        $arr = $result->toCustomerArray();
        self::assertArrayNotHasKey('defaultCost', $arr);
        self::assertArrayNotHasKey('calculatedCostPerMeter', $arr);
        self::assertArrayNotHasKey('dbco', $arr);
        self::assertArrayNotHasKey('baseCost', $arr);
        self::assertArrayNotHasKey('marginPercent', $arr);
    }

    public function test_stocked_uses_default_cost(): void
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

        $svc = $this->service($fake, true, 'BVA-STOCK/1C1BK', 'STOCKED', '99');
        $result = $svc->resolve('v1', $this->ctx(), false, null, false, '100');

        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->status);
        // (40/1)*100/50 = 80
        self::assertSame('80.000', $result->unitSellPrice);
        self::assertSame(CustomerSellPriceStatus::AVAILABILITY_STOCKED, $result->availability);
    }

    public function test_make_without_length_returns_length_required(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('KN-PAXN/1C500OR', '2507', '1', new CostResolution(
            productCode: 'KN-PAXN/1C500OR',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '10',
            defaultLength: null,
            requestedLength: '1',
            calculatedCostPerMeter: '10',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $svc = $this->service($fake, true, 'KN-PAXN/1C500OR', 'MAKE', '1');
        $result = $svc->resolve('v1', $this->ctx(), false, null, false, null);

        self::assertSame(CustomerSellPriceStatus::LENGTH_REQUIRED, $result->status);
        self::assertNull($result->unitSellPrice);
        self::assertTrue($result->lengthEnabled);
        self::assertTrue($result->quantityEnabled);
        self::assertFalse($result->canAddToRfq);
        self::assertSame('Enter length to calculate price', $result->displayMessage);
        self::assertSame(CustomerSellPriceStatus::AVAILABILITY_MAKE, $result->availability);
    }

    public function test_cost_api_fail_make_does_not_use_azure_cost(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('KN-PAXN/1C500OR', '2507', '170', CostResolution::failure(
            'KN-PAXN/1C500OR',
            '2507',
            'TIMEOUT',
            CustomerSellPriceStatus::AVAILABILITY_MAKE,
            '170',
        ));

        $svc = $this->service($fake, true, 'KN-PAXN/1C500OR', 'MAKE', '50.000');
        $result = $svc->resolve('v1', $this->ctx(), false, null, false, '170');

        self::assertSame(CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE, $result->status);
        self::assertNull($result->unitSellPrice);
        // Must not silently price from Azure 50 → 100
        self::assertNotSame('100.000', $result->unitSellPrice);
    }

    public function test_staff_override_still_works_with_cost_api_cost(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('KN-PAXN/1C500OR', '2507', '170', new CostResolution(
            productCode: 'KN-PAXN/1C500OR',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: null,
            defaultLength: null,
            requestedLength: '170',
            calculatedCostPerMeter: '113.291',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $svc = $this->service($fake, true, 'KN-PAXN/1C500OR', 'MAKE', '1');
        $result = $svc->resolve('v1', $this->ctx()->withOverrideMargin('31'), false, null, false, '170');

        // (113.291/1)*100/(100-31) = 164.189...
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->status);
        self::assertSame('164.189', $result->unitSellPrice);
    }

    public function test_invalid_length_rejected_at_service(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $svc = $this->service($fake, true, 'KN-PAXN/1C500OR', 'MAKE', '1');
        $result = $svc->resolve('v1', $this->ctx(), false, null, false, '0');
        self::assertSame(CustomerSellPriceStatus::REVIEW, $result->status);
        self::assertSame([], $fake->calls);

        $result2 = $svc->resolve('v1', $this->ctx(), false, null, false, 'abc');
        self::assertSame(CustomerSellPriceStatus::REVIEW, $result2->status);
    }

    public function test_disabled_gateway_keeps_azure_path(): void
    {
        $svc = $this->service(new DisabledLegacyCostCalculationGateway(), false, 'BVA-X/1C1BK', 'STOCKED', '100');
        $result = $svc->resolve('v1', $this->ctx());
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->status);
        self::assertSame('200.000', $result->unitSellPrice);
    }

    public function test_http_gateway_validates_and_never_exposes_costs_in_metrics_path(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'success' => true,
            'data' => [
                'productCode' => 'KN-PAXN/1C500OR',
                'dbco' => '2507',
                'status' => 'MAKE',
                'calculatedCostPerMeter' => '113.291',
                'defaultCost' => '50',
            ],
        ], JSON_THROW_ON_ERROR);
        $gw = new HttpLegacyCostCalculationGateway($http, 'https://www.tricab.com/cost-api/');
        $res = $gw->resolveCost('KN-PAXN/1C500OR', '2507', '170');
        self::assertTrue($res->success);
        self::assertSame('113.291', $res->effectiveCostPerUnit());
        self::assertStringContainsString('DBCO=2507', $http->calls[0]['url']);
        self::assertStringContainsString('userInputLength=170', $http->calls[0]['url']);

        // Cache hit — no second HTTP call
        $gw->resolveCost('KN-PAXN/1C500OR', '2507', '170');
        self::assertCount(1, $http->calls);
    }

    public function test_dbco_resolution(): void
    {
        $svc = new ResolvePricingDbcoService();
        $ctxAud = new CustomerPricingContext('c', 'AU', 'VIC', 'ELE', 'AUD', 'OK');
        self::assertSame('2507', $svc->resolve($ctxAud, 'TCA-BASE'));
        $ctxUsd = new CustomerPricingContext('c', 'US', null, null, 'USD', 'OK');
        self::assertSame('2517', $svc->resolve($ctxUsd, 'TCUS-BASE'));
        $ctxOther = new CustomerPricingContext('c', 'SG', null, null, 'SGD', 'OK');
        self::assertNull($svc->resolve($ctxOther, 'OTHER'));
        self::assertSame('2507', $svc->resolve($ctxAud, null));
    }

    public function test_stocked_cost_api_failure_falls_back_to_azure(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-STOCK/1C1BK', '2507', '100', CostResolution::failure(
            'BVA-STOCK/1C1BK',
            '2507',
            'TIMEOUT',
            CustomerSellPriceStatus::AVAILABILITY_STOCKED,
            '100',
        ));

        $svc = $this->service($fake, true, 'BVA-STOCK/1C1BK', 'STOCKED', '40');
        $diag = $svc->resolve('v1', $this->ctx(), true, null, false, '100');
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $diag->customer->status);
        self::assertSame('80.000', $diag->customer->unitSellPrice);
        self::assertContains('COST_API_FAILED_AZURE_STOCKED_FALLBACK', $diag->trace);
    }

    public function test_cost_api_without_azure_base_cost_resolves_sell_price(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('KN-PAXN/1C500OR', '2507', '170', new CostResolution(
            productCode: 'KN-PAXN/1C500OR',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '999',
            defaultLength: null,
            requestedLength: '170',
            calculatedCostPerMeter: '113.291',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seed('v-no-azure', [
            'variantPublicId' => 'v-no-azure',
            'variantCode' => 'KN-PAXN/1C500OR',
            'sku' => null,
            'styleCode' => 'BVA',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'KN-PAXN/1C500OR',
            'azureBase' => null,
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'ELE', 1, [
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
        );

        $result = $svc->resolve('v-no-azure', $this->ctx(), true, null, false, '170');
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->customer->status);
        self::assertSame('226.582', $result->customer->unitSellPrice);
        self::assertContains('BASE_META_FROM_CATALOG:TCA-BASE', $result->trace);
        self::assertContains('COST_SOURCE:LEGACY_COST_API', $result->trace);
    }

    public function test_cost_api_disabled_requires_azure_base_cost(): void
    {
        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seed('v-no-azure', [
            'variantPublicId' => 'v-no-azure',
            'variantCode' => 'KN-PAXN/1C500OR',
            'sku' => null,
            'styleCode' => 'BVA',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'KN-PAXN/1C500OR',
            'azureBase' => null,
        ]);

        $svc = new ResolveCustomerSellPriceService(
            $catalog,
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
            legacyCostApiEnabled: false,
        );

        $result = $svc->resolve('v-no-azure', $this->ctx(), false, null, false, '170');
        self::assertSame(CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, $result->status);
    }

    private function ctx(): CustomerPricingContext
    {
        return new CustomerPricingContext('cust-1', 'AU', 'VIC', 'ELE', 'AUD', 'OK');
    }

    private function service(
        FakeLegacyCostCalculationGateway|DisabledLegacyCostCalculationGateway $gateway,
        bool $enabled,
        string $variantCode,
        string $availability,
        string $azureBaseCost,
    ): ResolveCustomerSellPriceService {
        $base = new InMemoryVariantCommercialCatalogRepository();
        $base->seedFromLegacyBase('v1', [
            'baseCost' => $azureBaseCost,
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => $variantCode,
            'styleCode' => 'BVA',
            'availability' => $availability,
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'ELE', 1, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'sdi',
        ]);

        return new ResolveCustomerSellPriceService(
            $base,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $gateway,
            legacyCostApiEnabled: $enabled,
        );
    }
}
