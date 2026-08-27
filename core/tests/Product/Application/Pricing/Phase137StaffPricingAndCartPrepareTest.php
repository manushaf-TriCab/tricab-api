<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryVariantCommercialCatalogRepository;
use TriCab\Core\Product\Application\Pricing\InvalidMarginOverrideException;
use TriCab\Core\Product\Application\Pricing\PrepareCartItemPricingService;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\StaffMarginOverridePolicy;
use TriCab\Core\Product\Application\Pricing\StaffPricingContextTokenCodec;

final class Phase137StaffPricingAndCartPrepareTest extends TestCase
{
    public function test_margin_override_policy_allows_legacy_integer_range(): void
    {
        self::assertSame('-40', StaffMarginOverridePolicy::normalize(-40));
        self::assertSame('50', StaffMarginOverridePolicy::normalize(50));
        self::assertSame('31', StaffMarginOverridePolicy::normalize('31'));
        $this->expectException(InvalidMarginOverrideException::class);
        StaffMarginOverridePolicy::normalize(51);
    }

    public function test_staff_override_changes_sell_price_server_side(): void
    {
        $svc = $this->pricingService();
        $baseCtx = new CustomerPricingContext(
            customerPublicId: 'c1',
            countryCode: 'AU',
            territoryCode: 'VIC',
            marketCode: 'CONSULT1',
            sellCurrency: 'AUD',
            authState: 'OK',
        );
        $default = $svc->resolve('v1', $baseCtx);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $default->status);
        self::assertSame('48.244', $default->unitSellPrice);

        $override = $svc->resolve('v1', $baseCtx->withOverrideMargin('31'));
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $override->status);
        self::assertSame('34.959', $override->unitSellPrice);
    }

    public function test_cart_prepare_ignores_client_unit_price(): void
    {
        $prepare = new PrepareCartItemPricingService($this->pricingService());
        $ctx = new CustomerPricingContext(
            customerPublicId: 'c1',
            countryCode: 'AU',
            territoryCode: 'VIC',
            marketCode: 'CONSULT1',
            sellCurrency: 'AUD',
            authState: 'OK',
        );
        $result = $prepare->prepare('v1', $ctx, 1, 100, '0.01');
        self::assertTrue($result['accepted']);
        self::assertTrue($result['clientPriceIgnored']);
        self::assertFalse($result['clientPriceTrusted']);
        self::assertSame('48.244', $result['unitPrice']);
        self::assertSame('4824.40', $result['estimatedTotal']);
        self::assertSame('DURABLE_CART_AVAILABLE', $result['cartPersistence']);
    }

    public function test_pricing_context_token_round_trip(): void
    {
        $codec = new StaffPricingContextTokenCodec('test-secret-at-least-32-bytes-long!!');
        $token = $codec->issue('actor-1', 'cust-1', '31');
        $payload = $codec->decode($token);
        self::assertSame('actor-1', $payload['actorUserPublicId']);
        self::assertSame('cust-1', $payload['customerPublicId']);
        self::assertSame('31', $payload['overrideMargin']);
    }

    private function pricingService(): ResolveCustomerSellPriceService
    {
        $base = new InMemoryVariantCommercialCatalogRepository();
        $base->seedFromLegacyBase('v1', [
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
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'control',
        ]);

        return new ResolveCustomerSellPriceService(
            $base,
            $margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: new \TriCab\Api\Product\Pricing\DisabledLegacyCostCalculationGateway(),
            legacyCostApiEnabled: false,
        );
    }
}
