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

/** Phase 13.7F.4 — effective margin must change sell price (not just diagnostics). */
final class Phase137f4EffectiveMarginSellPriceTest extends TestCase
{
    private const VARIANT_ID = 'v-paxa';

    public function test_automatic_50_and_override_13_return_different_sell_prices(): void
    {
        $svc = $this->service();
        $ctx = $this->staffContext();

        $automatic = $this->diagnostic($svc->resolve(self::VARIANT_ID, $ctx, true, null, false, '100'));
        self::assertSame('50', $automatic->marginPercent);
        self::assertSame('44.498', $automatic->customer->unitSellPrice);

        $override = $this->diagnostic($svc->resolve(self::VARIANT_ID, $ctx->withOverrideMargin('13'), true, null, false, '100'));
        self::assertSame('50', $override->resolvedMarginPercent);
        self::assertSame('13', $override->overrideMarginPercent);
        self::assertSame('13', $override->marginPercent);
        self::assertSame('25.573', $override->customer->unitSellPrice);
        self::assertNotSame($automatic->customer->unitSellPrice, $override->customer->unitSellPrice);
    }

    public function test_margin_progression_13_20_31_each_changes_sell_price(): void
    {
        $svc = $this->service();
        $ctx = $this->staffContext();
        $prices = [];

        foreach (['13', '20', '31'] as $margin) {
            $result = $this->diagnostic($svc->resolve(self::VARIANT_ID, $ctx->withOverrideMargin($margin), true, null, false, '100'));
            self::assertSame($margin, $result->marginPercent);
            $prices[$margin] = $result->customer->unitSellPrice;
        }

        self::assertSame('25.573', $prices['13']);
        self::assertSame('27.811', $prices['20']);
        self::assertSame('32.244', $prices['31']);
        self::assertNotSame($prices['13'], $prices['20']);
        self::assertNotSame($prices['20'], $prices['31']);
    }

    public function test_clear_override_restores_automatic_sell_price(): void
    {
        $svc = $this->service();
        $ctx = $this->staffContext();

        $automatic = $this->diagnostic($svc->resolve(self::VARIANT_ID, $ctx, true, null, false, '100'));
        $override = $this->diagnostic($svc->resolve(self::VARIANT_ID, $ctx->withOverrideMargin('13'), true, null, false, '100'));
        $cleared = $this->diagnostic($svc->resolve(self::VARIANT_ID, $this->staffContext(), true, null, false, '100'));

        self::assertNotSame($automatic->customer->unitSellPrice, $override->customer->unitSellPrice);
        self::assertSame($automatic->customer->unitSellPrice, $cleared->customer->unitSellPrice);
        self::assertSame('50', $cleared->marginPercent);
    }

    public function test_formula_uses_margin_semantics_not_markup(): void
    {
        $svc = $this->service();
        $ctx = $this->staffContext()->withOverrideMargin('13');
        $result = $this->diagnostic($svc->resolve(self::VARIANT_ID, $ctx, true, null, false, '100'));

        self::assertSame('base/fx * 100/(100-margin)', $result->formulaPath);
        self::assertSame('22.249', $result->baseCost);
        // margin formula: 22.249 * 100 / (100 - 13) = 25.573...
        self::assertSame('25.573', $result->customer->unitSellPrice);
    }

    private function service(): ResolveCustomerSellPriceService
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-PAXA/5C50BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PAXA/5C50BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '22.249',
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '22.249',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seed(self::VARIANT_ID, [
            'variantPublicId' => self::VARIANT_ID,
            'variantCode' => 'BVA-PAXA/5C50BK',
            'sku' => null,
            'styleCode' => 'BVA',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'BVA-PAXA/5C50BK',
            'azureBase' => null,
        ]);

        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'CONSULT1', 5, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'control',
        ]);

        return new ResolveCustomerSellPriceService(
            $catalog,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
        );
    }

    private function staffContext(): CustomerPricingContext
    {
        return new CustomerPricingContext(
            customerPublicId: 'cust-ph13',
            countryCode: 'AU',
            territoryCode: 'VIC',
            marketCode: 'CONSULT1',
            sellCurrency: 'AUD',
            authState: 'OK',
            pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
        );
    }

    private function diagnostic(mixed $result): CustomerVariantSellPriceDiagnostic
    {
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $result);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->customer->status);

        return $result;
    }
}
