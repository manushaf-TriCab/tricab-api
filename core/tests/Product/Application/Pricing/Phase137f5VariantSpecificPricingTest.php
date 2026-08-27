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

/** Phase 13.7F.5 — variant-specific pricing (5C 1.5mm²) with staff override. */
final class Phase137f5VariantSpecificPricingTest extends TestCase
{
    private const PJXN = 'v-pjxn-5c15';
    private const PAXA = 'v-paxa-5c50';

    public function test_pjxn_5c15_resolves_with_44_percent_override(): void
    {
        $svc = $this->service();
        $ctx = $this->staffContext()->withOverrideMargin('44');

        $result = $this->diagnostic($svc->resolve(self::PJXN, $ctx, true, null, false, '100'));

        self::assertSame('44', $result->marginPercent);
        self::assertSame('44', $result->overrideMarginPercent);
        self::assertSame('8.753', $result->baseCost);
        self::assertSame('15.630', $result->customer->unitSellPrice);
        self::assertSame('STAFF_OVERRIDE', $result->marginSource);
    }

    public function test_paxa_control_also_resolves_with_same_override(): void
    {
        $svc = $this->service();
        $ctx = $this->staffContext()->withOverrideMargin('44');

        $pjxn = $this->diagnostic($svc->resolve(self::PJXN, $ctx, true, null, false, '100'));
        $paxa = $this->diagnostic($svc->resolve(self::PAXA, $ctx, true, null, false, '100'));

        self::assertNotSame($pjxn->customer->unitSellPrice, $paxa->customer->unitSellPrice);
        self::assertSame('39.730', $paxa->customer->unitSellPrice);
    }

    public function test_decimal_size_variant_code_uses_cost_api_product_code_unchanged(): void
    {
        $svc = $this->service();
        $result = $this->diagnostic(
            $svc->resolve(self::PJXN, $this->staffContext()->withOverrideMargin('44'), true, null, false, '100'),
        );

        self::assertContains('COST_API_CALL length=100', $result->trace);
        self::assertSame('BVA-PJXN/5C1.5BK', $result->customer->variantCode);
    }

    public function test_automatic_without_override_uses_default_zero_margin(): void
    {
        $svc = $this->service();
        $result = $svc->resolve(self::PJXN, $this->staffContext(), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $result);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->customer->status);
        self::assertSame('0', $result->marginPercent);
        self::assertContains('NO_MARGIN_RULE', $result->trace);
        self::assertContains('MARGIN_DEFAULT:0', $result->trace);
    }

    private function service(): ResolveCustomerSellPriceService
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-PJXN/5C1.5BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PJXN/5C1.5BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '3.243',
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '8.753',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));
        $fake->seed('BVA-PAXA/5C50BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PAXA/5C50BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '16.739',
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '22.249',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seed(self::PJXN, [
            'variantPublicId' => self::PJXN,
            'variantCode' => 'BVA-PJXN/5C1.5BK',
            'sku' => null,
            'styleCode' => 'BVA',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'BVA-PJXN/5C1.5BK',
            'azureBase' => null,
        ]);
        $catalog->seed(self::PAXA, [
            'variantPublicId' => self::PAXA,
            'variantCode' => 'BVA-PAXA/5C50BK',
            'sku' => null,
            'styleCode' => 'BVA',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'BVA-PAXA/5C50BK',
            'azureBase' => null,
        ]);

        return new ResolveCustomerSellPriceService(
            $catalog,
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
        );
    }

    private function staffContext(): CustomerPricingContext
    {
        return new CustomerPricingContext(
            customerPublicId: 'cust-bne',
            countryCode: 'AU',
            territoryCode: 'QLD-METRO',
            marketCode: 'ENERGY',
            sellCurrency: 'AUD',
            authState: 'OK',
            pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
            customerCode: 'BNE-ENR-001',
            customerDisplayName: 'Brisbane Energy Systems',
        );
    }

    private function diagnostic(mixed $result): CustomerVariantSellPriceDiagnostic
    {
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $result);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->customer->status);

        return $result;
    }
}
