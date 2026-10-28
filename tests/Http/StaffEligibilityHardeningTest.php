<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Http\Action\PublicProduct\PostCustomerVariantPricingBatchAction;
use TriCab\Api\Http\Auth\AssistantCatalogueVisibilityResolver;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\StaffMarginOverridePolicy;
use TriCab\Core\QuoteOrder\Application\Service\QuoteCreateCustomerEligibilityService;

/**
 * Security S1.2: only eligible staff identities (EMPLOYEE / ADMINISTRATOR, no
 * customer-oriented role) reach /api/v1/admin/*, act as someone else, price as
 * a customer or receive staff-only pricing data; price-as also needs a passkey
 * or Entra sign-in. Every user, customer and token here is synthetic and in
 * memory.
 */
final class StaffEligibilityHardeningTest extends TestCase
{
    private const TRIPWIRE_STATUS = 418;
    private const TRIPWIRE_HEADER = 'X-Test-Tripwire';
    private const TENANT = 'development-tenant';

    /** Mirrors DEV/seed-customer-roles-and-permissions.sql (CUSTOMER_USER). */
    private const CUSTOMER_USER_PERMISSIONS = [
        'ACCOUNT.LOGIN', 'CUSTOMER.READ', 'CUSTOMER.CONTACT.READ', 'PRODUCT.READ', 'QUOTE.READ', 'ORDER.READ', 'DOCUMENT.READ',
    ];

    private const STAFF_PRICING_PERMISSIONS = [
        'ACCOUNT.LOGIN', 'CUSTOMER.PRICE_AS', 'PRODUCT.PRICING.MANAGE', 'PRODUCT.PRICING.VIEW', 'QUOTE.CREATE',
    ];

    /**
     * @return iterable<string, array{list<string>, bool, string}>
     */
    public static function deniedAdminActors(): iterable
    {
        yield 'customer user holding every permission' => [['CUSTOMER_USER'], true, 'CUSTOMER_ACTOR_FORBIDDEN'];
        yield 'customer admin holding every permission' => [['CUSTOMER_ADMIN'], true, 'CUSTOMER_ACTOR_FORBIDDEN'];
        yield 'customer applicant' => [['CUSTOMER_APPLICANT'], false, 'CUSTOMER_ACTOR_FORBIDDEN'];
        yield 'newbie applicant' => [['NEWBIE'], false, 'CUSTOMER_ACTOR_FORBIDDEN'];
        yield 'future portal role' => [['PORTAL_COMPANY_ADMIN'], true, 'CUSTOMER_ACTOR_FORBIDDEN'];
        yield 'administrator who is also a customer admin' => [['EMPLOYEE', 'ADMINISTRATOR', 'CUSTOMER_ADMIN'], true, 'CUSTOMER_ACTOR_FORBIDDEN'];
        yield 'employee who is also an applicant' => [['EMPLOYEE', 'CUSTOMER_APPLICANT'], true, 'CUSTOMER_ACTOR_FORBIDDEN'];
        yield 'guest' => [['GUEST'], false, 'STAFF_ROLE_REQUIRED'];
        yield 'unknown custom role with every permission' => [['WAREHOUSE_SUPERVISOR'], true, 'STAFF_ROLE_REQUIRED'];
        yield 'specialist role without a staff role' => [['RFQ_SALES', 'CUSTOMER_CONTACT_COORDINATOR'], true, 'STAFF_ROLE_REQUIRED'];
    }

    /**
     * A tripwire on every admin route answers before route middleware and the
     * action, so the sweep proves the guard's decision without executing any
     * admin action, whichever way the decision goes.
     *
     * @param list<string> $roles
     */
    #[DataProvider('deniedAdminActors')]
    public function test_every_registered_admin_route_refuses_non_staff(array $roles, bool $allPermissions, string $expectedCode): void
    {
        $app = TestAppFactory::create();
        $routes = $this->armAdminTripwires($app);
        self::assertGreaterThan(300, count($routes), 'Admin route table looks incomplete.');
        $this->setActor($app, $roles, $allPermissions ? self::allPermissions() : ['ACCOUNT.LOGIN']);

        foreach ($routes as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, $method === 'GET' ? null : []));
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
            self::assertSame($expectedCode, $this->errorCode($response), $method . ' ' . $path);
            self::assertFalse($response->hasHeader(self::TRIPWIRE_HEADER), $method . ' ' . $path . ' reached the route.');
        }
    }

    public function test_every_registered_admin_route_admits_eligible_staff(): void
    {
        $app = TestAppFactory::create();
        $routes = $this->armAdminTripwires($app);
        $this->setActor($app, ['EMPLOYEE', 'ADMINISTRATOR', 'GUEST'], self::allPermissions());

        foreach ($routes as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, $method === 'GET' ? null : []));
            self::assertSame(self::TRIPWIRE_STATUS, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
        }
    }

    public function test_act_as_into_a_customer_is_judged_on_the_customer(): void
    {
        $app = TestAppFactory::create();
        $customer = $this->register($app, 'actas-customer', ['CUSTOMER_USER'], [...self::CUSTOMER_USER_PERMISSIONS, 'CUSTOMER.PRICE_AS']);
        $sessionId = $this->startActAs($app, $customer);

        $admin = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers')->withHeader('X-Act-As-Session', $sessionId));
        self::assertSame(403, $admin->getStatusCode(), (string) $admin->getBody());
        self::assertSame('CUSTOMER_ACTOR_FORBIDDEN', $this->errorCode($admin));

        $priceAs = $app->handle(TestAppFactory::request('GET', '/api/v1/pricing/customers?search=x')->withHeader('X-Act-As-Session', $sessionId));
        self::assertSame(403, $priceAs->getStatusCode(), (string) $priceAs->getBody());

        $cart = $app->handle(TestAppFactory::request('GET', '/api/v1/cart')->withHeader('X-Act-As-Session', $sessionId));
        self::assertSame(200, $cart->getStatusCode(), (string) $cart->getBody());

        $me = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $sessionId)));
        self::assertSame('CUSTOMER_ACTOR', $this->staffAccess($me)['eligibility']);
        self::assertFalse($this->staffAccess($me)['canPriceAs']);
    }

    public function test_act_as_into_a_staff_target_uses_the_administrators_sign_in_for_price_as(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Act As Priced Co', 'ACTAS-001');
        $sales = $this->register($app, 'actas-sales', ['EMPLOYEE'], ['account.login', 'CUSTOMER.PRICE_AS']);
        $sessionId = $this->startActAs($app, $sales);

        $response = $app->handle(
            $this->request('POST', '/api/v1/pricing/customer-context', ['customerId' => $customerPublicId])
                ->withHeader('X-Act-As-Session', $sessionId),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_open_act_as_session_stops_when_the_administrator_gains_a_customer_role(): void
    {
        $app = TestAppFactory::create();
        $target = $this->register($app, 'actas-target', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->startActAs($app, $target);
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $sessionId))->getStatusCode());

        $this->setActor($app, ['EMPLOYEE', 'ADMINISTRATOR', 'CUSTOMER_USER'], self::allPermissions());

        $switched = $app->handle(TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $sessionId));
        self::assertSame(403, $switched->getStatusCode(), (string) $switched->getBody());
        self::assertSame('act_as_invalid', $this->errorCode($switched));

        $start = $app->handle($this->request('POST', '/api/v1/admin/act-as', [
            'userPublicId' => $target->publicId()->toString(),
            'reason' => 'Synthetic support case',
        ]));
        self::assertSame(403, $start->getStatusCode(), (string) $start->getBody());
        self::assertSame('CUSTOMER_ACTOR_FORBIDDEN', $this->errorCode($start));
    }

    public function test_eligible_administrator_can_exit_act_as(): void
    {
        $app = TestAppFactory::create();
        $target = $this->register($app, 'actas-exit', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->startActAs($app, $target);

        $exit = $app->handle(TestAppFactory::request('DELETE', '/api/v1/admin/act-as/' . $sessionId)->withHeader('X-Act-As-Session', $sessionId));
        self::assertLessThan(300, $exit->getStatusCode(), (string) $exit->getBody());

        $after = $app->handle(TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $sessionId));
        self::assertSame(403, $after->getStatusCode(), (string) $after->getBody());
        self::assertSame('act_as_invalid', $this->errorCode($after));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lowAssuranceProviders(): iterable
    {
        yield 'email code' => [ExternalProvider::EMAIL_OTP];
        yield 'google' => [ExternalProvider::GOOGLE];
        yield 'apple' => [ExternalProvider::APPLE];
    }

    #[DataProvider('lowAssuranceProviders')]
    public function test_low_assurance_session_cannot_price_as(string $provider): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Low Assurance Co', 'LOW-001');
        $this->setActor($app, ['EMPLOYEE'], self::STAFF_PRICING_PERMISSIONS);
        $this->linkIdentity($app, $provider);

        foreach ($this->priceAsProbes($customerPublicId, 'synthetic-token') as [$method, $path, $body]) {
            $response = $app->handle($this->asProvider($this->request($method, $path, $body), $provider));
            self::assertSame(403, $response->getStatusCode(), $provider . ' ' . $method . ' ' . $path . ': ' . (string) $response->getBody());
        }
    }

    public function test_passkey_session_can_price_as_and_override_margin(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Passkey Co', 'PASSKEY-001');
        $this->setActor($app, ['EMPLOYEE'], self::STAFF_PRICING_PERMISSIONS);
        $this->linkIdentity($app, ExternalProvider::PASSKEY);

        $search = $app->handle($this->asProvider(TestAppFactory::request('GET', '/api/v1/pricing/customers?search=Passkey'), ExternalProvider::PASSKEY));
        self::assertSame(200, $search->getStatusCode(), (string) $search->getBody());

        $token = $this->selectCustomerToken($app, $customerPublicId, ExternalProvider::PASSKEY);
        $override = $app->handle($this->asProvider($this->request('POST', '/api/v1/pricing/margin-override', [
            'pricingContextToken' => $token,
            'margin' => StaffMarginOverridePolicy::allowedValues()[0],
        ]), ExternalProvider::PASSKEY));
        self::assertSame(200, $override->getStatusCode(), (string) $override->getBody());
    }

    public function test_token_issued_under_entra_is_refused_under_an_email_code_session(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Downgrade Co', 'DOWN-001');
        $this->setActor($app, ['EMPLOYEE'], self::STAFF_PRICING_PERMISSIONS);
        $token = $this->selectCustomerToken($app, $customerPublicId);
        $this->linkIdentity($app, ExternalProvider::EMAIL_OTP);

        foreach ($this->tokenOperations($token) as [$method, $path, $body]) {
            $response = $app->handle($this->asProvider(
                $this->request($method, $path, $body)->withHeader('X-Pricing-Context-Token', $token),
                ExternalProvider::EMAIL_OTP,
            ));
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
        }
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function staleTokenActors(): iterable
    {
        yield 'became a customer' => [['CUSTOMER_USER'], [...self::CUSTOMER_USER_PERMISSIONS, 'CUSTOMER.PRICE_AS']];
        yield 'became an applicant' => [['EMPLOYEE', 'CUSTOMER_APPLICANT'], self::STAFF_PRICING_PERMISSIONS];
        yield 'lost CUSTOMER.PRICE_AS' => [['EMPLOYEE'], ['ACCOUNT.LOGIN', 'CUSTOMER.READ', 'PRODUCT.PRICING.MANAGE']];
        yield 'lost the staff role' => [['RFQ_SALES'], self::STAFF_PRICING_PERMISSIONS];
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    #[DataProvider('staleTokenActors')]
    public function test_stale_staff_token_is_refused_on_every_cart_and_pricing_operation(array $roles, array $permissions): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Stale Token Co', 'STALE-001');
        $this->setActor($app, ['EMPLOYEE'], self::STAFF_PRICING_PERMISSIONS);
        $token = $this->selectCustomerToken($app, $customerPublicId);

        $this->setActor($app, $roles, $permissions);

        foreach ($this->tokenOperations($token) as [$method, $path, $body]) {
            $response = $app->handle($this->request($method, $path, $body)->withHeader('X-Pricing-Context-Token', $token));
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
        }
    }

    public function test_margin_override_requires_price_as_on_an_eligible_staff_identity(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Override Co', 'OVR-001');
        $this->setActor($app, ['EMPLOYEE'], self::STAFF_PRICING_PERMISSIONS);
        $token = $this->selectCustomerToken($app, $customerPublicId);
        $body = ['pricingContextToken' => $token, 'margin' => StaffMarginOverridePolicy::allowedValues()[0]];

        $allowed = $app->handle($this->request('POST', '/api/v1/pricing/margin-override', $body));
        self::assertSame(200, $allowed->getStatusCode(), (string) $allowed->getBody());

        foreach ([
            [['EMPLOYEE'], ['ACCOUNT.LOGIN', 'PRODUCT.PRICING.MANAGE', 'PRODUCT.PRICING.OVERRIDE']],
            [['WAREHOUSE_SUPERVISOR'], self::STAFF_PRICING_PERMISSIONS],
            [['CUSTOMER_ADMIN'], self::STAFF_PRICING_PERMISSIONS],
        ] as [$roles, $permissions]) {
            $this->setActor($app, $roles, $permissions);
            $response = $app->handle($this->request('POST', '/api/v1/pricing/margin-override', $body));
            self::assertSame(403, $response->getStatusCode(), implode(',', $roles) . ': ' . (string) $response->getBody());
        }
    }

    public function test_pricing_diagnostics_are_staff_only(): void
    {
        $view = ['ACCOUNT.LOGIN', 'PRODUCT.PRICING.VIEW'];

        self::assertTrue(PostCustomerVariantPricingBatchAction::allowsStaffDiagnostics($this->user(['EMPLOYEE'], $view)));
        self::assertFalse(PostCustomerVariantPricingBatchAction::allowsStaffDiagnostics($this->user(['CUSTOMER_USER'], $view)));
        self::assertFalse(PostCustomerVariantPricingBatchAction::allowsStaffDiagnostics($this->user(['EMPLOYEE', 'CUSTOMER_ADMIN'], $view)));
        self::assertFalse(PostCustomerVariantPricingBatchAction::allowsStaffDiagnostics($this->user(['GUEST'], $view)));
        self::assertFalse(PostCustomerVariantPricingBatchAction::allowsStaffDiagnostics($this->user(['WAREHOUSE_SUPERVISOR'], $view)));
        self::assertFalse(PostCustomerVariantPricingBatchAction::allowsStaffDiagnostics($this->user(['EMPLOYEE'], ['ACCOUNT.LOGIN'])));
        self::assertFalse(PostCustomerVariantPricingBatchAction::allowsStaffDiagnostics(null));
    }

    public function test_assistant_catalogue_visibility_is_staff_only(): void
    {
        $app = TestAppFactory::create();
        $resolver = $this->container($app)->get(AssistantCatalogueVisibilityResolver::class);
        self::assertInstanceOf(AssistantCatalogueVisibilityResolver::class, $resolver);
        $read = ['ACCOUNT.LOGIN', 'PRODUCT.READ'];

        $cases = [
            [ExternalProvider::MICROSOFT_ENTRA, ['EMPLOYEE'], AssistantCatalogueVisibility::STAFF],
            [ExternalProvider::MICROSOFT_ENTRA, ['CUSTOMER_USER'], AssistantCatalogueVisibility::PUBLIC],
            [ExternalProvider::MICROSOFT_ENTRA, ['EMPLOYEE', 'CUSTOMER_ADMIN'], AssistantCatalogueVisibility::PUBLIC],
            [ExternalProvider::MICROSOFT_ENTRA, ['CUSTOMER_APPLICANT'], AssistantCatalogueVisibility::PUBLIC],
            [ExternalProvider::MICROSOFT_ENTRA, ['GUEST'], AssistantCatalogueVisibility::PUBLIC],
            [ExternalProvider::MICROSOFT_ENTRA, ['WAREHOUSE_SUPERVISOR'], AssistantCatalogueVisibility::PUBLIC],
            [ExternalProvider::EMAIL_OTP, ['EMPLOYEE'], AssistantCatalogueVisibility::PUBLIC],
        ];
        foreach ($cases as [$provider, $roles, $expected]) {
            $request = TestAppFactory::request('POST', '/api/v1/assistant/messages')
                ->withAttribute(VerifiedIdentityAttribute::KEY, new VerifiedIdentity($provider, 'synthetic-subject', self::TENANT))
                ->withAttribute(AuthenticatedUserAttribute::KEY, $this->user($roles, $read));
            self::assertSame($expected, $resolver->resolve($request), $provider . ' ' . implode(',', $roles));
        }
    }

    public function test_quote_for_customer_needs_price_as_on_an_eligible_staff_identity(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Quote Target Co', 'QTGT-001');
        $eligibility = $this->container($app)->get(QuoteCreateCustomerEligibilityService::class);
        self::assertInstanceOf(QuoteCreateCustomerEligibilityService::class, $eligibility);

        self::assertTrue($eligibility->canCreateQuoteFor($this->user(['EMPLOYEE'], ['QUOTE.CREATE', 'CUSTOMER.PRICE_AS']), $customerPublicId));
        self::assertFalse($eligibility->canCreateQuoteFor($this->user(['EMPLOYEE'], ['QUOTE.CREATE', 'CUSTOMER.READ']), $customerPublicId));
        self::assertFalse($eligibility->canCreateQuoteFor($this->user(['EMPLOYEE'], ['CUSTOMER.PRICE_AS']), $customerPublicId));
        self::assertFalse($eligibility->canCreateQuoteFor($this->user(['WAREHOUSE_SUPERVISOR'], ['QUOTE.CREATE', 'CUSTOMER.PRICE_AS']), $customerPublicId));
        self::assertFalse($eligibility->canCreateQuoteFor($this->user(['GUEST'], ['QUOTE.CREATE', 'CUSTOMER.PRICE_AS']), $customerPublicId));
        self::assertFalse($eligibility->canCreateQuoteFor($this->user(['CUSTOMER_APPLICANT'], ['QUOTE.CREATE', 'CUSTOMER.PRICE_AS']), $customerPublicId));
        self::assertFalse($eligibility->canCreateQuoteFor($this->user(['CUSTOMER_USER'], ['QUOTE.CREATE', 'CUSTOMER.PRICE_AS']), $customerPublicId));
    }

    public function test_me_reports_the_servers_staff_access_decision(): void
    {
        $app = TestAppFactory::create();

        $this->setActor($app, ['EMPLOYEE'], self::STAFF_PRICING_PERMISSIONS);
        $entra = $this->staffAccess($this->data($app->handle(TestAppFactory::request('GET', '/api/v1/me'))));
        self::assertSame(['ELIGIBLE', 'high', true, true, false], array_values($entra));

        $this->linkIdentity($app, ExternalProvider::EMAIL_OTP);
        $low = $this->staffAccess($this->data($app->handle($this->asProvider(TestAppFactory::request('GET', '/api/v1/me'), ExternalProvider::EMAIL_OTP))));
        self::assertSame(['ELIGIBLE', 'low', false, false, true], array_values($low));

        $this->setActor($app, ['CUSTOMER_USER'], [...self::CUSTOMER_USER_PERMISSIONS, 'CUSTOMER.PRICE_AS']);
        $customer = $this->staffAccess($this->data($app->handle(TestAppFactory::request('GET', '/api/v1/me'))));
        self::assertSame(['CUSTOMER_ACTOR', 'high', false, false, false], array_values($customer));

        $this->setActor($app, ['GUEST'], ['ACCOUNT.LOGIN']);
        $guest = $this->staffAccess($this->data($app->handle(TestAppFactory::request('GET', '/api/v1/me'))));
        self::assertSame('NOT_STAFF', $guest['eligibility']);
    }

    public function test_public_catalogue_still_works_anonymously_and_for_guests(): void
    {
        $anonymous = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $guest = TestAppFactory::create();
        $this->setActor($guest, ['GUEST'], ['ACCOUNT.LOGIN']);

        foreach ([$anonymous, $guest] as $app) {
            foreach (['/api/v1/catalogue/tabs', '/api/v1/product-search?query=cable'] as $path) {
                $response = $app->handle(TestAppFactory::request('GET', $path));
                self::assertSame(200, $response->getStatusCode(), $path . ': ' . (string) $response->getBody());
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function allPermissions(): array
    {
        return array_values(array_unique([
            'ACCOUNT.LOGIN',
            ...DevelopmentUserManagementCatalog::managementPermissionCodes(),
            'CUSTOMER.PRICE_AS',
            'PRODUCT.PRICING.OVERRIDE',
            'PRODUCT.PRICING.MANAGE',
        ]));
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<array{string, string}>
     */
    private function armAdminTripwires(App $app): array
    {
        $tripwire = new class (self::TRIPWIRE_STATUS, self::TRIPWIRE_HEADER) implements MiddlewareInterface {
            public function __construct(private readonly int $status, private readonly string $header)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                unset($request, $handler);

                return (new ResponseFactory())->createResponse($this->status)->withHeader($this->header, 'reached');
            }
        };

        $routes = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if (!str_starts_with($route->getPattern(), '/api/v1/admin')) {
                continue;
            }
            $route->add($tripwire);
            foreach ($route->getMethods() as $method) {
                $routes[] = [$method, self::concretePath($route->getPattern())];
            }
        }

        return $routes;
    }

    private static function concretePath(string $pattern): string
    {
        $path = (string) preg_replace('/\[.*\]$/', '', $pattern);

        return (string) preg_replace_callback(
            '/\{([^}:]+)(?::([^}]+))?\}/',
            static function (array $match): string {
                $constraint = $match[2] ?? '';
                foreach (['00000000-0000-4000-8000-000000000001', '1', 'synthetic'] as $candidate) {
                    if ($constraint === '' || preg_match('~^(?:' . $constraint . ')$~', $candidate) === 1) {
                        return $candidate;
                    }
                }

                return '1';
            },
            $path,
        );
    }

    /**
     * @return list<array{string, string, array<string, mixed>|null}>
     */
    private function priceAsProbes(string $customerPublicId, string $token): array
    {
        return [
            ['GET', '/api/v1/pricing/customers?search=Co', null],
            ['GET', '/api/v1/pricing/customers/' . $customerPublicId . '/contacts', null],
            ['POST', '/api/v1/pricing/customer-context', ['customerId' => $customerPublicId]],
            ['POST', '/api/v1/pricing/margin-override', ['pricingContextToken' => $token, 'margin' => StaffMarginOverridePolicy::allowedValues()[0]]],
        ];
    }

    /**
     * @return list<array{string, string, array<string, mixed>|null}>
     */
    private function tokenOperations(string $token): array
    {
        $line = '00000000-0000-4000-8000-0000000000aa';
        $variant = ['variantId' => '00000000-0000-4000-8000-0000000000bb', 'quantity' => 1, 'length' => 100];

        return [
            ['GET', '/api/v1/cart', null],
            ['POST', '/api/v1/cart/items', $variant],
            ['PATCH', '/api/v1/cart/items/' . $line, ['quantity' => 2, 'rowVersion' => 'AAAAAAAAB9E=']],
            ['DELETE', '/api/v1/cart/items/' . $line, ['rowVersion' => 'AAAAAAAAB9E=']],
            ['POST', '/api/v1/cart/reprice', []],
            ['POST', '/api/v1/products/synthetic-product/cart/items/prepare', $variant],
            ['POST', '/api/v1/products/synthetic-product/rfq/lines/prepare', $variant],
            ['POST', '/api/v1/products/synthetic-product/variants/pricing', ['variantIds' => [$variant['variantId']], 'includeDiagnostic' => true]],
            ['POST', '/api/v1/pricing/margin-override', ['pricingContextToken' => $token, 'margin' => StaffMarginOverridePolicy::allowedValues()[0]]],
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function selectCustomerToken(App $app, string $customerPublicId, ?string $provider = null): string
    {
        $request = $this->request('POST', '/api/v1/pricing/customer-context', ['customerId' => $customerPublicId]);
        $response = $app->handle($provider === null ? $request : $this->asProvider($request, $provider));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $token = $this->data($response)['pricingContextToken'] ?? null;
        self::assertIsString($token);
        self::assertSame(CustomerPricingContext::MODE_STAFF_SELECTED, $this->data($response)['mode'] ?? null);

        return $token;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function startActAs(App $app, User $target): string
    {
        $response = $app->handle($this->request('POST', '/api/v1/admin/act-as', [
            'userPublicId' => $target->publicId()->toString(),
            'reason' => 'Synthetic support case',
        ]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $sessionId = $this->data($response)['sessionId'] ?? null;
        self::assertIsString($sessionId);

        return $sessionId;
    }

    private function asProvider(ServerRequestInterface $request, string $provider): ServerRequestInterface
    {
        return $request->withAttribute(
            VerifiedIdentityAttribute::KEY,
            new VerifiedIdentity($provider, 'synthetic-' . $provider, self::TENANT),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function linkIdentity(App $app, string $provider): void
    {
        $repository = $this->userRepository($app);
        $user = $repository->findById(UserId::fromInt(1));
        self::assertInstanceOf(User::class, $user);
        $repository->linkExternalIdentity($user, new ExternalIdentity(
            ExternalProvider::fromString($provider),
            ExternalSubjectId::fromString('synthetic-' . $provider),
            ExternalTenantId::fromString(self::TENANT),
        ));
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function register(App $app, string $subject, array $roles, array $permissions): User
    {
        return $this->userRepository($app)->register(
            EmailAddress::fromString($subject . '@example.test'),
            $subject,
            UserStatus::active(),
            array_map(static fn (string $code): RoleCode => RoleCode::fromString($code), $roles),
            array_map(static fn (string $code): PermissionCode => PermissionCode::fromString($code), $permissions),
            new ExternalIdentity(
                ExternalProvider::fromString(ExternalProvider::MICROSOFT_ENTRA),
                ExternalSubjectId::fromString($subject),
                ExternalTenantId::fromString(self::TENANT),
            ),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function setActor(App $app, array $roles, array $permissions): void
    {
        $this->userRepository($app)->replacePermissions(1, array_values(array_unique($permissions)), $roles);
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function user(array $roles, array $permissions): AuthenticatedUser
    {
        return new AuthenticatedUser(9100, '00000000-0000-4000-8000-000000009100', 'synthetic.user@example.test', 'Synthetic User', 'active', $roles, $permissions);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createCustomer(App $app, string $customerName, string $customerCode): string
    {
        $response = $app->handle($this->request('POST', '/api/v1/admin/customers', [
            'customerName' => $customerName,
            'customerCode' => $customerCode,
            'countryCode' => 'AU',
        ]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $publicId = $this->data($response)['publicId'] ?? null;
        self::assertIsString($publicId);

        return $publicId;
    }

    /**
     * @param array<string, mixed> $me
     * @return array<string, mixed>
     */
    private function staffAccess(array $me): array
    {
        $staffAccess = $me['staffAccess'] ?? null;
        self::assertIsArray($staffAccess);

        /** @var array<string, mixed> $staffAccess */
        return $staffAccess;
    }

    private function errorCode(ResponseInterface $response): ?string
    {
        $payload = TestAppFactory::json($response);
        $error = $payload['error'] ?? null;

        return is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $payload = TestAppFactory::json($response);
        $data = $payload['data'] ?? null;
        self::assertIsArray($data, (string) $response->getBody());

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, ?array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path);
        if ($body === null) {
            return $request;
        }

        $request = $request->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function userRepository(App $app): InMemoryApiUserRepository
    {
        $repository = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);

        return $repository;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function container(App $app): \DI\Container
    {
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);

        return $container;
    }
}
