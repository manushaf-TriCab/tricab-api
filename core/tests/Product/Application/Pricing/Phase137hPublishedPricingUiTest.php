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

/** Phase 13.7H — published non-BVA variants enter the same runtime Cost API pipeline. */
final class Phase137hPublishedPricingUiTest extends TestCase
{
    /** @var list<array{id: string, code: string}> */
    private const RCA_VARIANTS = [
        ['id' => 'rca-dbxd', 'code' => 'RCA-DBXD/4P.75BK'],
        ['id' => 'rca-de2d', 'code' => 'RCA-DE2D/4P.75BK'],
        ['id' => 'rca-dexd', 'code' => 'RCA-DEXD/4P.75BK'],
        ['id' => 'rca-dexn', 'code' => 'RCA-DEXN/4P.75BK'],
        ['id' => 'rca-dfxd', 'code' => 'RCA-DFXD/4P.75GY'],
        ['id' => 'rca-dfxn', 'code' => 'RCA-DFXN/4P.75GY'],
    ];

    public function test_rca_without_azure_prices_is_pricing_eligible(): void
    {
        $diag = $this->resolveRca('rca-dbxd', 'RCA-DBXD/4P.75BK', override: '31');
        self::assertSame('RCA-DBXD/4P.75BK', $diag->customer->variantCode);
        self::assertContains('COST_SOURCE:LEGACY_COST_API', $diag->trace);
        self::assertNotContains('NO_AZURE_BASE_COST', $diag->trace);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $diag->customer->status);
    }

    public function test_does_not_require_bva_style_or_product_variant_prices(): void
    {
        $catalog = $this->rcaCatalog(azureBase: false);
        $fake = $this->seedAllRcaCosts();
        $svc = $this->service($catalog, $fake, withMargin: false);

        $diag = $svc->resolve('rca-dbxd', $this->ctx(), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $diag);
        self::assertSame('RCA', $this->styleFromTraceOrCatalog($catalog, 'rca-dbxd'));
        self::assertNotSame('BVA', $this->styleFromTraceOrCatalog($catalog, 'rca-dbxd'));
        self::assertContains('COST_SOURCE:LEGACY_COST_API', $diag->trace);
    }

    public function test_six_visible_rca_variants_all_enter_the_batch(): void
    {
        $catalog = $this->rcaCatalog(azureBase: false);
        $svc = $this->service($catalog, $this->seedAllRcaCosts(), withMargin: false);
        $ids = array_column(self::RCA_VARIANTS, 'id');
        $batch = $svc->resolveBatch($ids, $this->ctx(), null, '100');

        self::assertCount(6, $batch);
        foreach ($batch as $index => $item) {
            self::assertSame(self::RCA_VARIANTS[$index]['code'], $item->variantCode);
            self::assertContains(
                $item->status,
                [CustomerSellPriceStatus::TBC, CustomerSellPriceStatus::RESOLVED],
            );
        }
    }

    public function test_missing_margin_uses_default_zero_and_resolves(): void
    {
        $diag = $this->resolveRca('rca-dbxd', 'RCA-DBXD/4P.75BK', override: null);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $diag->customer->status);
        self::assertNotNull($diag->customer->unitSellPrice);
        self::assertSame('0', $diag->marginPercent);
        self::assertSame('TMMKTM', $diag->marginSource);
        self::assertContains('NO_MARGIN_RULE', $diag->trace);
        self::assertContains('MARGIN_DEFAULT:0', $diag->trace);
        self::assertContains('COST_SOURCE:LEGACY_COST_API', $diag->trace);
        self::assertNotSame(CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE, $diag->customer->status);
    }

    public function test_empty_default_margin_keeps_tbc(): void
    {
        $fake = $this->seedAllRcaCosts();
        $catalog = $this->rcaCatalog(azureBase: false);
        $svc = new ResolveCustomerSellPriceService(
            $catalog,
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
            pricingCostSource: 'legacy-cost-api',
            defaultTmmktmMargin: '',
        );
        $diag = $svc->resolve('rca-dbxd', $this->ctx(), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $diag);
        self::assertSame(CustomerSellPriceStatus::TBC, $diag->customer->status);
        self::assertContains('NO_MARGIN_RULE', $diag->trace);
        self::assertNotContains('MARGIN_DEFAULT:0', $diag->trace);
    }

    public function test_staff_override_resolves_numeric_sell_price(): void
    {
        $diag = $this->resolveRca('rca-dbxd', 'RCA-DBXD/4P.75BK', override: '31');
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $diag->customer->status);
        self::assertNotNull($diag->customer->unitSellPrice);
        self::assertSame('31', $diag->overrideMarginPercent);
        self::assertSame('STAFF_OVERRIDE', $diag->marginSource);
    }

    public function test_genuine_cost_api_failure_is_unavailable(): void
    {
        $fake = new FakeLegacyCostCalculationGateway();
        $fake->seed('RCA-DBXD/4P.75BK', '2507', '100', CostResolution::failure(
            'RCA-DBXD/4P.75BK',
            '2507',
            'TIMEOUT',
            CustomerSellPriceStatus::AVAILABILITY_MAKE,
            '100',
        ));
        $catalog = $this->rcaCatalog(azureBase: false);
        $svc = $this->service($catalog, $fake, withMargin: false);
        $diag = $svc->resolve('rca-dbxd', $this->ctx(), true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $diag);
        self::assertSame(CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE, $diag->customer->status);
    }

    public function test_mixed_rca_batch_stays_isolated(): void
    {
        $fake = $this->seedAllRcaCosts();
        $fake->seed('RCA-DEXD/4P.75BK', '2507', '100', CostResolution::failure(
            'RCA-DEXD/4P.75BK',
            '2507',
            'TIMEOUT',
            CustomerSellPriceStatus::AVAILABILITY_MAKE,
            '100',
        ));
        $catalog = $this->rcaCatalog(azureBase: false);
        $svc = $this->service($catalog, $fake, withMargin: false);
        $ctx = $this->ctx()->withOverrideMargin('31');
        $batch = $svc->resolveBatch(array_column(self::RCA_VARIANTS, 'id'), $ctx, null, '100');

        self::assertSame(CustomerSellPriceStatus::RESOLVED, $batch[0]->status);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $batch[1]->status);
        self::assertSame(CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE, $batch[2]->status);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $batch[3]->status);
    }

    public function test_cost_api_receives_the_real_rca_variant_code(): void
    {
        $fake = $this->seedAllRcaCosts();
        $catalog = $this->rcaCatalog(azureBase: false);
        $svc = $this->service($catalog, $fake, withMargin: false);
        $svc->resolve('rca-dbxd', $this->ctx(), true, null, false, '100');
        self::assertSame('RCA-DBXD/4P.75BK', $fake->calls[0]['productCode'] ?? null);
        self::assertSame('100', $fake->calls[0]['requestedLength'] ?? null);
    }

    private function resolveRca(string $id, string $code, ?string $override): CustomerVariantSellPriceDiagnostic
    {
        $fake = $this->seedAllRcaCosts();
        $catalog = $this->rcaCatalog(azureBase: false);
        $svc = $this->service($catalog, $fake, withMargin: false);
        $ctx = $override === null ? $this->ctx() : $this->ctx()->withOverrideMargin($override);
        $result = $svc->resolve($id, $ctx, true, null, false, '100');
        self::assertInstanceOf(CustomerVariantSellPriceDiagnostic::class, $result);
        self::assertSame($code, $result->customer->variantCode);

        return $result;
    }

    /**
     * @return InMemoryVariantCommercialCatalogRepository
     */
    private function rcaCatalog(bool $azureBase): InMemoryVariantCommercialCatalogRepository
    {
        $catalog = new InMemoryVariantCommercialCatalogRepository();
        foreach (self::RCA_VARIANTS as $variant) {
            $catalog->seed($variant['id'], [
                'variantPublicId' => $variant['id'],
                'variantCode' => $variant['code'],
                'sku' => $variant['code'],
                'styleCode' => 'RCA',
                'uomCode' => 'M',
                'uomSymbol' => 'm',
                'legacyProductCode' => $variant['code'],
                'azureBase' => $azureBase
                    ? [
                        'baseCost' => '1.000',
                        'currencyCode' => 'AUD',
                        'priceListCode' => 'TCA-BASE',
                        'availability' => 'MAKE',
                    ]
                    : null,
            ]);
        }

        return $catalog;
    }

    private function seedAllRcaCosts(): FakeLegacyCostCalculationGateway
    {
        $fake = new FakeLegacyCostCalculationGateway();
        foreach (self::RCA_VARIANTS as $index => $variant) {
            $fake->seed($variant['code'], '2507', '100', new CostResolution(
                productCode: $variant['code'],
                dbco: '2507',
                status: 'MAKE',
                defaultCost: null,
                defaultLength: null,
                requestedLength: '100',
                calculatedCostPerMeter: (string) (10 + $index),
                legacyUnitCode: null,
                success: true,
                failureReason: null,
            ));
        }

        return $fake;
    }

    private function service(
        InMemoryVariantCommercialCatalogRepository $catalog,
        FakeLegacyCostCalculationGateway $fake,
        bool $withMargin,
    ): ResolveCustomerSellPriceService {
        $margins = new InMemoryCustomerPricingMarginRepository();
        if ($withMargin) {
            $margins->seed('RCA', 'AU', 'QLD-METRO', 'ENERGY', 0, [
                'marginPercent' => '40',
                'status' => 'OK',
                'ruleLevel' => 'termkt',
                'coreClass' => 'p',
            ]);
        }

        return new ResolveCustomerSellPriceService(
            $catalog,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: $fake,
            legacyCostApiEnabled: true,
            pricingCostSource: 'legacy-cost-api',
        );
    }

    private function ctx(): CustomerPricingContext
    {
        return new CustomerPricingContext(
            'cust-bne',
            'AU',
            'QLD-METRO',
            'ENERGY',
            'AUD',
            'OK',
            CustomerPricingContext::MODE_STAFF_SELECTED,
        );
    }

    private function styleFromTraceOrCatalog(
        InMemoryVariantCommercialCatalogRepository $catalog,
        string $variantId,
    ): string {
        $row = $catalog->findCommercialCatalog($variantId, gmdate('Y-m-d'));

        return (string) ($row['styleCode'] ?? '');
    }
}
