<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryVariantBaseCostRepository;
use TriCab\Core\Product\Application\Pricing\PrepareRfqLinePricingService;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;

final class PrepareRfqLinePricingServiceTest extends TestCase
{
    public function test_server_ignores_tampered_client_unit_price(): void
    {
        $svc = $this->service();
        $ctx = new CustomerPricingContext('cust-1', 'AU', 'VIC', 'CONSULT1', 'AUD', 'OK');
        $result = $svc->prepare('v1', $ctx, 1, 100, '0.01');

        self::assertTrue($result['accepted']);
        self::assertFalse($result['clientPriceTrusted']);
        self::assertTrue($result['clientPriceIgnored']);
        self::assertSame('19.886', $result['unitPrice']);
        self::assertSame('1988.60', $result['estimatedTotal']);
        self::assertSame(100, $result['totalMetres']);
    }

    public function test_tbc_does_not_become_zero(): void
    {
        $base = new InMemoryVariantBaseCostRepository();
        $base->seed('v1', [
            'baseCost' => '9.943',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-X',
            'styleCode' => 'BVA',
            'availability' => 'UNKNOWN',
        ]);
        $svc = new PrepareRfqLinePricingService(
            new ResolveCustomerSellPriceService(
                $base,
                new InMemoryCustomerPricingMarginRepository(),
                new InMemoryCurrencyFxRateRepository(),
            ),
        );
        $ctx = new CustomerPricingContext('cust-1', 'AU', 'VIC', 'ELE', 'AUD', 'OK');
        $result = $svc->prepare('v1', $ctx, 1, 100, '0.00');

        self::assertSame(CustomerSellPriceStatus::TBC, $result['status']);
        self::assertNull($result['unitPrice']);
        self::assertNull($result['estimatedTotal']);
        self::assertFalse($result['accepted']);
    }

    public function test_quantity_two_doubles_total(): void
    {
        $svc = $this->service();
        $ctx = new CustomerPricingContext('cust-1', 'AU', 'VIC', 'CONSULT1', 'AUD', 'OK');
        $one = $svc->prepare('v1', $ctx, 1, 100, null);
        $two = $svc->prepare('v1', $ctx, 2, 100, null);
        self::assertSame('1988.60', $one['estimatedTotal']);
        self::assertSame('3977.20', $two['estimatedTotal']);
        self::assertSame(200, $two['totalMetres']);
    }

    private function service(): PrepareRfqLinePricingService
    {
        $base = new InMemoryVariantBaseCostRepository();
        $base->seed('v1', [
            'baseCost' => '9.943',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-CAXD/10C2.5BK',
            'styleCode' => 'BVA',
            'availability' => 'UNKNOWN',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'CONSULT1', 10, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'control',
        ]);

        return new PrepareRfqLinePricingService(
            new ResolveCustomerSellPriceService($base, $margins, new InMemoryCurrencyFxRateRepository()),
        );
    }
}
