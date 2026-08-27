<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Commerce\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Commerce\Persistence\InMemoryCartRepository;
use TriCab\Api\Commerce\Persistence\UnknownVariantAvailabilityRepository;
use TriCab\Core\Commerce\Application\Service\AddCartItemService;
use TriCab\Core\Commerce\Application\Service\CartDtoAssembler;
use TriCab\Core\Commerce\Application\Service\CartEffectiveCustomerResolver;
use TriCab\Core\Commerce\Application\Service\GetCartService;
use TriCab\Core\Commerce\Application\Service\RemoveCartItemService;
use TriCab\Core\Commerce\Application\Service\RepriceCartService;
use TriCab\Core\Commerce\Application\Service\UpdateCartItemService;
use TriCab\Core\Commerce\Domain\Exception\CartConcurrencyConflict;
use TriCab\Core\Commerce\Domain\ValueObject\MarginSource;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryVariantCommercialCatalogRepository;
use TriCab\Core\Product\Application\Pricing\PricingActorContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class Phase137aDurableCartTest extends TestCase
{
    private InMemoryCartRepository $carts;
    private ResolveCustomerSellPriceService $pricing;
    private InMemoryCustomerPricingMarginRepository $margins;
    private ApplicationTransaction $tx;
    private CartDtoAssembler $assembler;
    private CartEffectiveCustomerResolver $customerResolver;
    private UnknownVariantAvailabilityRepository $availability;

    protected function setUp(): void
    {
        $this->carts = new InMemoryCartRepository();
        $this->carts->seedVariant('v1', 'BVA-CAXD/37C1.5BK', 'BVA Cable');
        $this->margins = new InMemoryCustomerPricingMarginRepository();
        $this->margins->seed('BVA', 'AU', 'VIC', 'CONSULT1', 37, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'control',
        ]);
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
        $this->pricing = new ResolveCustomerSellPriceService(
            $base,
            $this->margins,
            new InMemoryCurrencyFxRateRepository(),
            legacyCost: new \TriCab\Api\Product\Pricing\DisabledLegacyCostCalculationGateway(),
            legacyCostApiEnabled: false,
        );
        $this->tx = new PassthroughApplicationTransaction();
        $this->assembler = new CartDtoAssembler();
        $customers = $this->createMock(CustomerRepository::class);
        $this->customerResolver = new CartEffectiveCustomerResolver($customers);
        $this->availability = new UnknownVariantAvailabilityRepository();
    }

    public function test_create_cart_add_line_and_merge(): void
    {
        $add = $this->addService($this->context('c1'));
        $user = $this->user();

        $first = $add->execute($user, 'v1', 1, 100, null, null, '0.01');
        self::assertTrue($first['accepted']);
        self::assertTrue($first['clientPriceIgnored']);
        self::assertSame('DURABLE', $first['cartPersistence']);
        self::assertSame(1, $first['itemCount']);
        self::assertSame('48.244', $first['items'][0]['unitPrice']);
        self::assertSame('4824.40', $first['items'][0]['lineTotal']);
        self::assertSame('4824.40', $first['subtotal']);

        $second = $add->execute($user, 'v1', 1, 100);
        self::assertSame(1, $second['itemCount']);
        self::assertSame(2, $second['items'][0]['quantity']);
        self::assertSame('9648.80', $second['items'][0]['lineTotal']);
    }

    public function test_customer_isolation_separate_open_carts(): void
    {
        $user = $this->user();
        $a = $this->addService($this->context('c1'))->execute($user, 'v1', 1, 100);
        $b = $this->addService($this->context('c2'))->execute($user, 'v1', 1, 50);

        self::assertNotSame($a['cartId'], $b['cartId']);
        self::assertSame(1, $a['itemCount']);
        self::assertSame(1, $b['itemCount']);
        self::assertSame('4824.40', $a['subtotal']);
        self::assertSame('2412.20', $b['subtotal']);
    }

    public function test_currency_isolation(): void
    {
        $user = $this->user();
        $aud = $this->addService($this->context('c1', 'AUD'))->execute($user, 'v1', 1, 100);
        $nzd = $this->addService($this->context('c1', 'NZD'))->execute($user, 'v1', 1, 100);

        self::assertNotSame($aud['cartId'], $nzd['cartId']);
        self::assertSame('AUD', $aud['currency']);
        self::assertSame('NZD', $nzd['currency']);
    }

    public function test_default_zero_margin_prices_are_never_fake_zero(): void
    {
        $ctx = new CustomerPricingContext(
            customerPublicId: 'c-tbc',
            countryCode: 'AU',
            territoryCode: 'XXX',
            marketCode: 'NONE',
            sellCurrency: 'AUD',
            authState: 'OK',
        );
        $result = $this->addService($ctx)->execute($this->user(), 'v1', 1, 100);

        self::assertTrue($result['accepted']);
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $result['items'][0]['pricingStatus']);
        self::assertNotNull($result['items'][0]['unitPrice']);
        self::assertNotNull($result['items'][0]['lineTotal']);
        self::assertFalse($result['hasUnpricedLines']);
        self::assertNotSame('0.00', $result['items'][0]['unitPrice']);
    }

    public function test_update_qty_length_and_remove(): void
    {
        $user = $this->user();
        $ctx = $this->context('c1');
        $added = $this->addService($ctx)->execute($user, 'v1', 1, 100);
        $lineId = $added['items'][0]['lineId'];
        $rowVersion = $added['items'][0]['rowVersion'];

        $updated = $this->updateService($ctx)->execute($user, $lineId, 2, '50', $rowVersion);
        self::assertSame(2, $updated['items'][0]['quantity']);
        self::assertSame('50.000', $updated['items'][0]['length']);
        self::assertSame('4824.40', $updated['items'][0]['lineTotal']);

        $rowVersion2 = $updated['items'][0]['rowVersion'];
        $removed = $this->removeService($ctx)->execute($user, $lineId, $rowVersion2);
        self::assertSame(0, $removed['itemCount']);
        self::assertSame('0.00', $removed['subtotal']);
    }

    public function test_reprice_price_changed_with_commit(): void
    {
        $user = $this->user();
        $baseCtx = $this->context('c1');
        $added = $this->addService($baseCtx)->execute($user, 'v1', 1, 100);
        self::assertSame('48.244', $added['items'][0]['unitPrice']);

        $overrideCtx = $baseCtx->withOverrideMargin('31');
        $preview = $this->repriceService($overrideCtx)->execute($user, false);
        self::assertSame(RepriceCartService::CHANGE_PRICE_CHANGED, $preview['changes'][0]['change']);
        self::assertSame('34.959', $preview['changes'][0]['proposedUnitPrice']);
        self::assertSame('48.244', $preview['cart']['items'][0]['unitPrice']);

        $committed = $this->repriceService($overrideCtx)->execute($user, true);
        self::assertTrue($committed['commit']);
        self::assertSame('34.959', $committed['cart']['items'][0]['unitPrice']);
        self::assertSame('3495.90', $committed['cart']['items'][0]['lineTotal']);
    }

    public function test_concurrency_conflict_on_stale_row_version(): void
    {
        $user = $this->user();
        $ctx = $this->context('c1');
        $added = $this->addService($ctx)->execute($user, 'v1', 1, 100);
        $lineId = $added['items'][0]['lineId'];
        $stale = $added['items'][0]['rowVersion'];

        $this->updateService($ctx)->execute($user, $lineId, 2, null, $stale);

        $this->expectException(CartConcurrencyConflict::class);
        $this->updateService($ctx)->execute($user, $lineId, 3, null, $stale);
    }

    public function test_staff_override_margin_source(): void
    {
        $user = $this->user(staff: true);
        $ctx = $this->context('c1')->withOverrideMargin('31');
        // Force staff mode for DTO staff fields
        $staffCtx = new CustomerPricingContext(
            customerPublicId: $ctx->customerPublicId,
            countryCode: $ctx->countryCode,
            territoryCode: $ctx->territoryCode,
            marketCode: $ctx->marketCode,
            sellCurrency: $ctx->sellCurrency,
            authState: 'OK',
            pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
            overrideMarginPercent: '31',
            customerCode: 'C1',
            customerDisplayName: 'Customer One',
        );

        $result = $this->addService($staffCtx)->execute($user, 'v1', 1, 100);
        self::assertSame('34.959', $result['items'][0]['unitPrice']);
        self::assertSame('3495.90', $result['items'][0]['lineTotal']);
        self::assertTrue($result['marginOverrideActive'] ?? false);
        self::assertSame('31', $result['marginOverridePercent'] ?? null);

        $cart = $this->carts->findOpenCartForActor(
            $user->userId,
            $this->customerResolver->resolve($staffCtx),
            \TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode::fromString(CustomerPricingContext::MODE_STAFF_SELECTED),
            'AUD',
        );
        self::assertNotNull($cart);
        self::assertSame(MarginSource::STAFF_OVERRIDE, $cart->lines()[0]->marginSource());
    }

    public function test_get_cart_creates_empty_open_cart(): void
    {
        $ctx = $this->context('c1');
        $get = new GetCartService(
            $this->carts,
            $this->fixedActor($ctx),
            $this->customerResolver,
            $this->assembler,
            $this->tx,
        );
        $result = $get->execute($this->user());
        self::assertSame(0, $result['itemCount']);
        self::assertSame('OPEN', $result['status']);
        self::assertSame('AUD', $result['currency']);
    }

    private function addService(CustomerPricingContext $context): AddCartItemService
    {
        return new AddCartItemService(
            $this->carts,
            $this->fixedActor($context),
            $this->pricing,
            $this->availability,
            $this->customerResolver,
            $this->assembler,
            $this->tx,
        );
    }

    private function updateService(CustomerPricingContext $context): UpdateCartItemService
    {
        return new UpdateCartItemService(
            $this->carts,
            $this->fixedActor($context),
            $this->pricing,
            $this->availability,
            $this->assembler,
            $this->customerResolver,
            $this->tx,
        );
    }

    private function removeService(CustomerPricingContext $context): RemoveCartItemService
    {
        return new RemoveCartItemService(
            $this->carts,
            $this->fixedActor($context),
            $this->assembler,
            $this->customerResolver,
            $this->tx,
        );
    }

    private function repriceService(CustomerPricingContext $context): RepriceCartService
    {
        return new RepriceCartService(
            $this->carts,
            $this->fixedActor($context),
            $this->pricing,
            $this->availability,
            $this->assembler,
            $this->customerResolver,
            $this->tx,
        );
    }

    private function fixedActor(CustomerPricingContext $context): PricingActorContextResolver
    {
        return new class ($context) implements PricingActorContextResolver {
            public function __construct(private readonly CustomerPricingContext $context)
            {
            }

            public function fromAuthenticatedUser(
                ?AuthenticatedUser $user,
                ?string $pricingContextToken = null,
            ): CustomerPricingContext {
                unset($user, $pricingContextToken);

                return $this->context;
            }
        };
    }

    private function context(string $customerPublicId, string $currency = 'AUD'): CustomerPricingContext
    {
        return new CustomerPricingContext(
            customerPublicId: $customerPublicId,
            countryCode: 'AU',
            territoryCode: 'VIC',
            marketCode: 'CONSULT1',
            sellCurrency: $currency,
            authState: 'OK',
            customerCode: strtoupper($customerPublicId),
            customerDisplayName: 'Customer ' . $customerPublicId,
        );
    }

    private function user(bool $staff = false): AuthenticatedUser
    {
        return new AuthenticatedUser(
            userId: 42,
            publicId: '11111111-1111-4111-8111-111111111111',
            emailAddress: 'cart@example.com',
            displayName: 'Cart Tester',
            status: 'ACTIVE',
            roles: $staff ? ['STAFF'] : ['CUSTOMER'],
            permissions: $staff
                ? ['CUSTOMER.PRICE_AS', 'PRODUCT.PRICING.OVERRIDE']
                : [],
        );
    }
}
