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
use TriCab\Core\Product\Application\Pricing\StaffMarginOverridePolicy;
use TriCab\Core\Product\Application\Pricing\StaffPricingContextTokenCodec;

final class Phase137f3MarginOverridePrecedenceTest extends TestCase
{
    public function test_resolved_50_with_selected_3_uses_effective_3_and_changes_price(): void
    {
        $svc = $this->pricingServiceWithAutomaticMargin('50');
        $ctx = $this->staffContext();

        $automatic = $svc->resolve('v1', $ctx, true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $automatic);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $automatic->customer->status);
        self::assertSame('50', $automatic->resolvedMarginPercent);
        self::assertNull($automatic->overrideMarginPercent);
        self::assertSame('50', $automatic->marginPercent);
        self::assertSame('48.244', $automatic->customer->unitSellPrice);

        $overridden = $svc->resolve('v1', $ctx->withOverrideMargin('3'), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $overridden);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $overridden->customer->status);
        self::assertSame('50', $overridden->resolvedMarginPercent);
        self::assertSame('3', $overridden->overrideMarginPercent);
        self::assertSame('3', $overridden->marginPercent);
        self::assertSame('STAFF_OVERRIDE', $overridden->marginSource);
        self::assertNotSame($automatic->customer->unitSellPrice, $overridden->customer->unitSellPrice);
        self::assertSame('24.868', $overridden->customer->unitSellPrice);
    }

    public function test_low_override_margins_use_integer_percent_not_truthiness(): void
    {
        $svc = $this->pricingServiceWithAutomaticMargin('50');
        $ctx = $this->staffContext();

        foreach (['1', '3', '10', '31'] as $margin) {
            $result = $svc->resolve('v1', $ctx->withOverrideMargin($margin), true, null, false, '100');
            self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $result);
            self::assertSame($margin, $result->marginPercent, 'effective margin for ' . $margin);
            self::assertSame($margin, $result->overrideMarginPercent, 'override margin for ' . $margin);
            self::assertSame('50', $result->resolvedMarginPercent, 'resolved margin preserved for ' . $margin);
        }
    }

    public function test_margin_override_policy_normalizes_integer_percent(): void
    {
        self::assertSame('3', StaffMarginOverridePolicy::normalize(3));
        self::assertSame('3', StaffMarginOverridePolicy::normalize('3'));
        self::assertSame('0', StaffMarginOverridePolicy::normalize(0));
    }

    public function test_new_context_token_carries_override_and_decodes_for_batch(): void
    {
        $codec = new StaffPricingContextTokenCodec('test-secret-at-least-32-bytes-long!!');
        $baseToken = $codec->issue('actor-staff-uuid-0001', '52A45BFB-AC8F-F111-B337-6045BD3F9734', null, '58A45BFB-AC8F-F111-B337-6045BD3F9734');
        $overrideToken = $codec->issue('actor-staff-uuid-0001', '52A45BFB-AC8F-F111-B337-6045BD3F9734', '3', '58A45BFB-AC8F-F111-B337-6045BD3F9734');

        self::assertNotSame($baseToken, $overrideToken);
        self::assertSame('3', $codec->decode($overrideToken)['overrideMargin']);
        self::assertNull($codec->decode($baseToken)['overrideMargin']);
    }

    public function test_clear_override_restores_automatic_margin_price(): void
    {
        $svc = $this->pricingServiceWithAutomaticMargin('50');
        $ctx = $this->staffContext();

        $automatic = $svc->resolve('v1', $ctx, true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $automatic);

        $overridden = $svc->resolve('v1', $ctx->withOverrideMargin('3'), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $overridden);
        self::assertNotSame($automatic->customer->unitSellPrice, $overridden->customer->unitSellPrice);

        $cleared = $svc->resolve('v1', $ctx->withOverrideMargin(null), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $cleared);
        self::assertSame($automatic->customer->unitSellPrice, $cleared->customer->unitSellPrice);
        self::assertSame('50', $cleared->marginPercent);
        self::assertNull($cleared->overrideMarginPercent);
    }

    public function test_tbc_automatic_with_override_resolves_numeric_and_clear_returns_tbc(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-PAXD/2C35BK', '2507', '100', new CostResolution(
            productCode: 'BVA-PAXD/2C35BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '18.912',
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '18.912',
            legacyUnitCode: null,
            success: true,
            failureReason: null,
        ));

        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seed('v-bne', [
            'variantPublicId' => 'v-bne',
            'variantCode' => 'BVA-PAXD/2C35BK',
            'sku' => null,
            'styleCode' => 'BVA',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'BVA-PAXD/2C35BK',
            'azureBase' => null,
        ]);

        $svc = new ResolveCustomerSellPriceService(
            $catalog,
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
        );

        $ctx = new CustomerPricingContext(
            customerPublicId: 'cust-bne',
            countryCode: 'AU',
            territoryCode: 'QLD-METRO',
            marketCode: 'ENERGY',
            sellCurrency: 'AUD',
            authState: 'OK',
            pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
        );

        $automatic = $svc->resolve('v-bne', $ctx, true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $automatic);
        self::assertSame(CustomerSellPriceStatus::TBC, $automatic->customer->status);

        $overridden = $svc->resolve('v-bne', $ctx->withOverrideMargin('3'), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $overridden);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $overridden->customer->status);
        self::assertSame('3', $overridden->marginPercent);

        $cleared = $svc->resolve('v-bne', $ctx->withOverrideMargin(null), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $cleared);
        self::assertSame(CustomerSellPriceStatus::TBC, $cleared->customer->status);
    }

    private function pricingServiceWithAutomaticMargin(string $marginPercent): ResolveCustomerSellPriceService
    {
        $catalog = new InMemoryVariantCommercialCatalogRepository();
        $catalog->seedFromLegacyBase('v1', [
            'baseCost' => '24.122',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-CAXD/37C1.5BK',
            'styleCode' => 'BVA',
            'availability' => 'UNKNOWN',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'CONSULT1', 37, [
            'marginPercent' => $marginPercent,
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'control',
        ]);

        return new ResolveCustomerSellPriceService(
            $catalog,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
        );
    }

    private function staffContext(): CustomerPricingContext
    {
        return new CustomerPricingContext(
            customerPublicId: 'c1',
            countryCode: 'AU',
            territoryCode: 'VIC',
            marketCode: 'CONSULT1',
            sellCurrency: 'AUD',
            authState: 'OK',
            pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
        );
    }
}
