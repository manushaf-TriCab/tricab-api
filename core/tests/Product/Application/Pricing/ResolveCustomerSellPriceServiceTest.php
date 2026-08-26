<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\Pricing\CableMarginPrecedenceResolver;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryVariantBaseCostRepository;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract;

final class ResolveCustomerSellPriceServiceTest extends TestCase
{
    public function test_formula_100_at_20_percent_is_125(): void
    {
        $formula = new LegacyCustomerSellFormulaContract();
        self::assertSame('125.000000000000', $formula->resolveSellPrice('100', '1', '20'));
        self::assertNotSame(
            $formula->resolveSellPrice('100', '1', '20'),
            $formula->incorrectMarkupShortcut('100', '20'),
        );
    }

    public function test_resolved_au_customer_price(): void
    {
        $base = new InMemoryVariantBaseCostRepository();
        $base->seed('v1', [
            'baseCost' => '100',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-PAXD/1C185BK',
            'styleCode' => 'BVA',
            'availability' => 'STOCKED',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'ELE', 1, [
            'marginPercent' => '20',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'sdi',
        ]);

        $svc = new ResolveCustomerSellPriceService($base, $margins, new InMemoryCurrencyFxRateRepository());
        $ctx = new CustomerPricingContext('cust-1', 'AU', 'VIC', 'ELE', 'AUD', 'OK');
        $result = $svc->resolve('v1', $ctx);

        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result->status);
        self::assertSame('125.000', $result->unitSellPrice);
        self::assertSame('AUD', $result->currencyCode);
        $arr = $result->toCustomerArray();
        self::assertArrayNotHasKey('baseCost', $arr);
        self::assertArrayNotHasKey('marginPercent', $arr);
    }

    public function test_guest_login_required(): void
    {
        $svc = new ResolveCustomerSellPriceService(
            new InMemoryVariantBaseCostRepository(),
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
        );
        $result = $svc->resolve('v1', CustomerPricingContext::guest());
        self::assertSame(CustomerSellPriceStatus::LOGIN_REQUIRED, $result->status);
        self::assertNull($result->unitSellPrice);
    }

    public function test_missing_base_cost_price_not_available(): void
    {
        $svc = new ResolveCustomerSellPriceService(
            new InMemoryVariantBaseCostRepository(),
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
        );
        $ctx = new CustomerPricingContext('cust-1', 'AU', 'VIC', 'ELE', 'AUD', 'OK');
        $result = $svc->resolve('missing', $ctx);
        self::assertSame(CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, $result->status);
    }

    public function test_no_margin_is_tbc(): void
    {
        $base = new InMemoryVariantBaseCostRepository();
        $base->seed('v1', [
            'baseCost' => '10',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-X/2C1BK',
            'styleCode' => 'BVA',
            'availability' => 'STOCKED',
        ]);
        $svc = new ResolveCustomerSellPriceService(
            $base,
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
        );
        $ctx = new CustomerPricingContext('cust-1', 'AU', 'VIC', 'ELE', 'AUD', 'OK');
        $result = $svc->resolve('v1', $ctx);
        self::assertSame(CustomerSellPriceStatus::TBC, $result->status);
    }

    public function test_unsupported_currency_fx_not_available(): void
    {
        $base = new InMemoryVariantBaseCostRepository();
        $base->seed('v1', [
            'baseCost' => '10',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-X/1C1BK',
            'styleCode' => 'BVA',
            'availability' => 'STOCKED',
        ]);
        $svc = new ResolveCustomerSellPriceService(
            $base,
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
        );
        $ctx = new CustomerPricingContext('cust-1', 'AU', 'VIC', 'ELE', 'EUR', 'OK');
        $result = $svc->resolve('v1', $ctx);
        self::assertSame(CustomerSellPriceStatus::FX_NOT_AVAILABLE, $result->status);
    }

    public function test_zero_base_cost_blocked(): void
    {
        $base = new InMemoryVariantBaseCostRepository();
        $base->seed('v1', [
            'baseCost' => '0',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-X/1C1BK',
            'styleCode' => 'BVA',
            'availability' => 'STOCKED',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', null, null, 1, [
            'marginPercent' => '20',
            'status' => 'OK',
            'ruleLevel' => 'ct',
            'coreClass' => 'sdi',
        ]);
        $svc = new ResolveCustomerSellPriceService($base, $margins, new InMemoryCurrencyFxRateRepository());
        $ctx = new CustomerPricingContext('cust-1', 'AU', null, null, 'AUD', 'OK');
        $result = $svc->resolve('v1', $ctx);
        self::assertSame(CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, $result->status);
    }

    public function test_tas_rule_not_applied_by_default(): void
    {
        $base = new InMemoryVariantBaseCostRepository();
        $base->seed('v1', [
            'baseCost' => '99',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-X/1C1BK',
            'styleCode' => 'BVA',
            'availability' => 'STOCKED',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'TAS', null, 1, [
            'marginPercent' => '20',
            'status' => 'OK',
            'ruleLevel' => 'ter',
            'coreClass' => 'sdi',
        ]);
        $svc = new ResolveCustomerSellPriceService($base, $margins, new InMemoryCurrencyFxRateRepository());
        $ctx = new CustomerPricingContext('cust-1', 'AU', 'TAS', null, 'AUD', 'OK');
        $diag = $svc->resolve('v1', $ctx, true);
        self::assertFalse($diag->tasRuleApplied);
        self::assertSame('123.750', $diag->customer->unitSellPrice);
    }

    public function test_margin_precedence_termkt_beats_country(): void
    {
        $resolver = new CableMarginPrecedenceResolver();
        $rows = [
            ['withStyle' => true, 'level' => 'ct', 'ncond' => 1, 'marginPercent' => '10'],
            ['withStyle' => true, 'level' => 'termkt', 'ncond' => 1, 'marginPercent' => '25'],
        ];
        $resolved = $resolver->resolve($rows, 1);
        self::assertSame('25', $resolved['marginPercent']);
        self::assertSame('termkt', $resolved['ruleLevel']);
    }

    public function test_customer_array_never_contains_base_cost(): void
    {
        $base = new InMemoryVariantBaseCostRepository();
        $base->seed('v1', [
            'baseCost' => '2.134',
            'currencyCode' => 'AUD',
            'priceListCode' => 'TCA-BASE',
            'uomCode' => 'M',
            'uomSymbol' => 'm',
            'variantCode' => 'BVA-X/1C1BK',
            'styleCode' => 'BVA',
            'availability' => 'STOCKED',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', null, null, 1, [
            'marginPercent' => '20',
            'status' => 'OK',
            'ruleLevel' => 'ct',
            'coreClass' => 'sdi',
        ]);
        $svc = new ResolveCustomerSellPriceService($base, $margins, new InMemoryCurrencyFxRateRepository());
        $json = json_encode(
            $svc->resolve('v1', new CustomerPricingContext('c', 'AU', null, null, 'AUD', 'OK'))->toCustomerArray(),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString('baseCost', $json);
        self::assertStringNotContainsString('2.134', $json);
    }
}
