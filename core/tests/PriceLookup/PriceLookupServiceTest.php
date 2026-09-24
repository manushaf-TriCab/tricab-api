<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\PriceLookup;

use PHPUnit\Framework\TestCase;
use TriCab\Api\PriceLookup\Persistence\InMemoryPriceLookupFxRepository;
use TriCab\Api\PriceLookup\Persistence\InMemoryPriceLookupPriceRepository;
use TriCab\Api\Product\Pricing\FakeLegacyCostCalculationGateway;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupForbidden;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;
use TriCab\Core\PriceLookup\Application\Service\QuotePriceLookupService;
use TriCab\Core\PriceLookup\Application\Service\SearchPriceLookupService;
use TriCab\Core\PriceLookup\Domain\PriceLookupColourMask;
use TriCab\Core\PriceLookup\Domain\PriceLookupDbcoMapper;
use TriCab\Core\PriceLookup\Domain\PriceLookupMetricRate;
use TriCab\Core\PriceLookup\Domain\StaffEnteredMarginSellFormula;
use TriCab\Core\PriceLookup\Module;
use TriCab\Core\Product\Application\Pricing\CostResolution;

final class PriceLookupServiceTest extends TestCase
{
    public function test_company_maps_to_dbco_including_tcasia(): void
    {
        $mapper = new PriceLookupDbcoMapper();
        self::assertSame('2507', $mapper->toDbco('TCA'));
        self::assertSame('2517', $mapper->toDbco('TCUS'));
        self::assertSame('2519', $mapper->toDbco('TCASIA'));
    }

    public function test_metric_rate_matches_legacy_tca_and_tcus(): void
    {
        $metric = new PriceLookupMetricRate();
        self::assertSame('1', $metric->mrate('metre', '2507'));
        self::assertSame('1', $metric->mrate('foot', '2517'));
        self::assertSame('3.2808399', $metric->mrate('foot', '2507'));
        self::assertSame(bcdiv('1', '3.2808399', 12), $metric->mrate('metre', '2517'));
    }

    public function test_staff_margin_sell_formula(): void
    {
        $formula = new StaffEnteredMarginSellFormula();
        self::assertSame('10.00', $formula->apply('10.00', null));
        self::assertSame('12.500000000000', $formula->apply('10', '20'));
        $this->expectException(PriceLookupValidationException::class);
        $formula->apply('10', '100');
    }

    public function test_colour_mask_strips_colour_suffix(): void
    {
        $mask = new PriceLookupColourMask();
        $masked = $mask->maskForLookup('KL-PAXN/5C6BK');
        self::assertSame('KL-XXXN/5C6', $masked);
    }

    public function test_search_requires_manage_for_star(): void
    {
        $prices = new InMemoryPriceLookupPriceRepository();
        $prices->seed([
            'productCode' => 'LSUP-A',
            'cost' => '10',
            'setupLength' => '500',
            'lastUpdated' => "1st Jan'26",
            'warehouseStatus' => '2302',
            'dbco' => '2507',
        ]);
        $service = new SearchPriceLookupService($prices);
        $this->expectException(PriceLookupForbidden::class);
        $service->execute('*', 'TCA', [Module::PERMISSION_READ]);
    }

    public function test_quote_uses_cost_api_then_applies_staff_margin(): void
    {
        $prices = new InMemoryPriceLookupPriceRepository();
        $prices->seed([
            'productCode' => 'LSUP-TEST/1C10',
            'cost' => '100',
            'setupLength' => '500',
            'lastUpdated' => "1st Jan'26",
            'warehouseStatus' => '2302',
            'dbco' => '2507',
        ]);
        $fx = new InMemoryPriceLookupFxRepository(['AUD' => '1']);
        $costApi = new FakeLegacyCostCalculationGateway();
        $costApi->seed(
            'LSUP-TEST/1C10',
            '2507',
            '1',
            new CostResolution(
                productCode: 'LSUP-TEST/1C10',
                dbco: '2507',
                status: 'STOCKED',
                defaultCost: '100',
                defaultLength: '500',
                requestedLength: '1',
                calculatedCostPerMeter: '80',
                legacyUnitCode: 'M',
                success: true,
                failureReason: null,
            ),
        );

        $service = new QuotePriceLookupService($prices, $fx, $costApi);
        $result = $service->execute('LSUP-TEST/1C10', 'TCA', 'AUD', 'metre', null, '20');

        self::assertSame('STOCK', $result->status);
        self::assertSame(QuotePriceLookupService::COST_SOURCE_API, $result->costSource);
        self::assertSame('80.00', $result->adjustedCost);
        self::assertSame('100.00', $result->sell);
        self::assertSame('TCA', $result->company);
    }

    public function test_quote_falls_back_when_cost_api_fails(): void
    {
        $prices = new InMemoryPriceLookupPriceRepository();
        $prices->seed([
            'productCode' => 'LSUP-FALLBACK',
            'cost' => '50',
            'setupLength' => '100',
            'lastUpdated' => "1st Jan'26",
            'warehouseStatus' => null,
            'dbco' => '2507',
        ]);
        $fx = new InMemoryPriceLookupFxRepository(['AUD' => '1']);
        $costApi = new FakeLegacyCostCalculationGateway();

        $service = new QuotePriceLookupService($prices, $fx, $costApi);
        $result = $service->execute('LSUP-FALLBACK', 'TCA', 'AUD', 'metre', null, null);

        self::assertSame('MAKE', $result->status);
        self::assertContains($result->costSource, [
            QuotePriceLookupService::COST_SOURCE_LEGACY_FALLBACK,
            QuotePriceLookupService::COST_SOURCE_BASE,
        ]);
        self::assertSame('50.00', $result->adjustedCost);
        self::assertSame('50.00', $result->sell);
    }
}
