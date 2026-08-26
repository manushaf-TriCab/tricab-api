<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Action\PublicProduct\PostCustomerVariantPricingBatchAction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContextResolver;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository;
use TriCab\Core\Product\Application\Pricing\InMemoryVariantBaseCostRepository;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;

use TriCab\Api\Tests\TestAppFactory;

final class CustomerVariantPricingBatchActionTest extends TestCase
{
    public function test_rejects_client_supplied_margin_and_dbco(): void
    {
        $action = $this->action(CustomerPricingContext::guest());
        $response = $action($this->request(['variantIds' => ['v1'], 'marginPercent' => 20]), new \Slim\Psr7\Response());
        self::assertSame(422, $response->getStatusCode());

        $response2 = $action($this->request(['variantIds' => ['v1'], 'dbco' => 2507]), new \Slim\Psr7\Response());
        self::assertSame(422, $response2->getStatusCode());
    }

    public function test_guest_returns_login_required_without_base_cost(): void
    {
        $action = $this->action(CustomerPricingContext::guest());
        $response = $action($this->request(['variantIds' => ['v1']]), new \Slim\Psr7\Response());
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('baseCost', $json);
        self::assertSame(CustomerSellPriceStatus::LOGIN_REQUIRED, $payload['data']['items'][0]['status']);
        self::assertNull($payload['data']['items'][0]['unitPrice']);
    }

    public function test_resolved_customer_dto_omits_internal_fields(): void
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
            'availability' => 'STOCKED',
        ]);
        $margins = new InMemoryCustomerPricingMarginRepository();
        $margins->seed('BVA', 'AU', 'VIC', 'CONSULT1', 10, [
            'marginPercent' => '50',
            'status' => 'OK',
            'ruleLevel' => 'termkt',
            'coreClass' => 'control',
        ]);
        $pricing = new ResolveCustomerSellPriceService($base, $margins, new InMemoryCurrencyFxRateRepository());
        $action = $this->action(
            new CustomerPricingContext('cust-1', 'AU', 'VIC', 'CONSULT1', 'AUD', 'OK'),
            $pricing,
        );

        $forbidden = $action($this->request(['variantIds' => ['v1'], 'baseCost' => '1']), new \Slim\Psr7\Response());
        self::assertSame(422, $forbidden->getStatusCode());

        $ok = $action($this->request(['variantIds' => ['v1']]), new \Slim\Psr7\Response());
        $payload = json_decode((string) $ok->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $item = $payload['data']['items'][0];
        self::assertSame(CustomerSellPriceStatus::RESOLVED, $item['status']);
        self::assertSame('19.886', $item['unitPrice']);
        self::assertArrayNotHasKey('baseCost', $item);
        self::assertArrayNotHasKey('marginPercent', $item);
        self::assertStringNotContainsString('baseCost', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function action(
        CustomerPricingContext $context,
        ?ResolveCustomerSellPriceService $pricing = null,
    ): PostCustomerVariantPricingBatchAction {
        $contextResolver = new class ($context) implements CustomerPricingContextResolver {
            public function __construct(private readonly CustomerPricingContext $fixed)
            {
            }

            public function fromAuthenticatedUser(?AuthenticatedUser $user): CustomerPricingContext
            {
                unset($user);

                return $this->fixed;
            }
        };

        $pricing ??= new ResolveCustomerSellPriceService(
            new InMemoryVariantBaseCostRepository(),
            new InMemoryCustomerPricingMarginRepository(),
            new InMemoryCurrencyFxRateRepository(),
        );

        $requestUser = new class extends ResolveRequestAuthenticatedUser {
            public function __construct()
            {
                // Test stub — parent handler unused.
            }

            public function fromRequest(\Psr\Http\Message\ServerRequestInterface $request): ?AuthenticatedUser
            {
                unset($request);

                return null;
            }
        };

        return new PostCustomerVariantPricingBatchAction(
            $requestUser,
            $contextResolver,
            $pricing,
            new JsonResponder(),
            new ProductManagementErrorMapper(new JsonResponder()),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): ServerRequestInterface
    {
        return TestAppFactory::request('POST', '/api/v1/products/bva/variants/pricing')
            ->withParsedBody($body)
            ->withAttribute(AuthenticatedUserAttribute::KEY, null);
    }
}
