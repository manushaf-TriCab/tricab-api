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
use TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract;

/** Phase 13.7F.6 — BVA-CBXD/9C1BK negative margin + control comparison. */
final class Phase137f6CbxdNegativeMarginTest extends TestCase
{
    private const CBXD = '299A4365-1492-F111-B336-000D3AE07ABC';
    private const PAXA = '839A4365-1492-F111-B336-000D3AE07ABC';

    /** @return list<int> */
    public static function marginMatrixProvider(): array
    {
        return [
            'automatic' => [null],
            'minus31' => [-31],
            'minus8' => [-8],
            'plus3' => [3],
            'plus10' => [10],
            'plus31' => [31],
            'plus44' => [44],
        ];
    }

    public function test_negative_margin_policy_allows_minus31(): void
    {
        self::assertSame('-31', StaffMarginOverridePolicy::normalize(-31));
        self::assertContains(-31, StaffMarginOverridePolicy::allowedValues());
    }

    public function test_cbxd_9c1bk_resolves_with_minus31_override(): void
    {
        $svc = $this->service();
        $ctx = $this->staffContext()->withOverrideMargin('-31');

        $cbxd = $this->diagnostic($svc->resolve(self::CBXD, $ctx, true, null, false, '100'));
        $paxa = $this->diagnostic($svc->resolve(self::PAXA, $ctx, true, null, false, '100'));

        self::assertSame('BVA-CBXD/9C1BK', $cbxd->customer->variantCode);
        self::assertSame('-31', $cbxd->overrideMarginPercent);
        self::assertSame('-31', $cbxd->marginPercent);
        self::assertSame('STAFF_OVERRIDE', $cbxd->marginSource);
        self::assertSame('12.291', $cbxd->baseCost);
        self::assertSame('9.382', $cbxd->customer->unitSellPrice);
        self::assertSame(CustomerSellPriceStatus::AVAILABILITY_MAKE, $cbxd->customer->availability);

        self::assertSame('BVA-PAXA/5C50BK', $paxa->customer->variantCode);
        self::assertSame('16.983', $paxa->customer->unitSellPrice);
        self::assertNotSame($cbxd->customer->unitSellPrice, $paxa->customer->unitSellPrice);
    }

    public function test_negative_margin_formula_denominator_131(): void
    {
        $formula = new LegacyCustomerSellFormulaContract();
        $sell = $formula->resolveSellPrice('12.291', '1', '-31');
        self::assertSame('131.000000000000', bcsub('100', '-31', 12));
        self::assertSame('9.382442748091', $sell);
    }

    public function test_automatic_bne_context_stays_tbc_for_cbxd(): void
    {
        $svc = $this->service();
        $result = $svc->resolve(self::CBXD, $this->staffContext(), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $result);
        self::assertSame(CustomerSellPriceStatus::TBC, $result->customer->status);
        self::assertSame('Customer pricing setup incomplete', $result->customer->displayMessage);
        self::assertContains('NO_MARGIN_RULE', $result->trace);
    }

    /**
     * @dataProvider marginMatrixProvider
     */
    public function test_margin_matrix(?int $margin): void
    {
        $svc = $this->service();
        $ctx = $margin === null
            ? $this->staffContext()
            : $this->staffContext()->withOverrideMargin((string) $margin);

        $cbxd = $svc->resolve(self::CBXD, $ctx, true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $cbxd);

        if ($margin === null) {
            self::assertSame(CustomerSellPriceStatus::TBC, $cbxd->customer->status);

            return;
        }

        self::assertSame(CustomerSellPriceStatus::RESOLVED, $cbxd->customer->status);
        self::assertSame((string) $margin, $cbxd->marginPercent);
        self::assertNotNull($cbxd->customer->unitSellPrice);
    }

    private function service(): ResolveCustomerSellPriceService
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('BVA-CBXD/9C1BK', '2507', '100', new CostResolution(
            productCode: 'BVA-CBXD/9C1BK',
            dbco: '2507',
            status: 'MAKE',
            defaultCost: '3.243',
            defaultLength: null,
            requestedLength: '100',
            calculatedCostPerMeter: '12.291',
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
        $catalog->seed(self::CBXD, [
            'variantPublicId' => self::CBXD,
            'variantCode' => 'BVA-CBXD/9C1BK',
            'sku' => null,
            'styleCode' => 'BVA',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'legacyProductCode' => 'BVA-CBXD/9C1BK',
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
