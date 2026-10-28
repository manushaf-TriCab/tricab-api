<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\QuoteOrder\Persistence\InMemoryQuoteOrderRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;

/**
 * Security S1: customer-facing roles are kept off staff administration and
 * staff price-as, whatever permission codes they carry. All data is synthetic
 * and in memory.
 */
final class CustomerActorStaffIsolationTest extends TestCase
{
    private const ACTOR_PUBLIC_ID = DevelopmentUserManagementCatalog::USER_PUBLIC_ID;

    /** Mirrors DEV/seed-customer-roles-and-permissions.sql (CUSTOMER_USER). */
    private const CUSTOMER_USER_PERMISSIONS = [
        'ACCOUNT.LOGIN',
        'CUSTOMER.READ',
        'CUSTOMER.CONTACT.READ',
        'PRODUCT.READ',
        'QUOTE.READ',
        'ORDER.READ',
        'DOCUMENT.READ',
    ];

    /** Mirrors DEV/seed-customer-roles-and-permissions.sql (CUSTOMER_ADMIN). */
    private const CUSTOMER_ADMIN_PERMISSIONS = [
        ...self::CUSTOMER_USER_PERMISSIONS,
        'CUSTOMER.UPDATE',
        'CUSTOMER.CONTACT.CREATE',
        'CUSTOMER.CONTACT.UPDATE',
        'CUSTOMER.CONTACT.STATUS.UPDATE',
        'CUSTOMER.USER.READ',
        'CUSTOMER.USER.INVITE',
        'CUSTOMER.USER.LINK',
        'CUSTOMER.USER.UNLINK',
        'CUSTOMER.PORTAL_ACCESS.MANAGE',
        'QUOTE.CREATE',
    ];

    private const STAFF_ADMIN_PERMISSIONS = [
        'ACCOUNT.LOGIN',
        'CUSTOMER.READ',
        'CUSTOMER.UPDATE',
        'CUSTOMER.CONTACT.READ',
        'CUSTOMER.CONTACT.CREATE',
        'CUSTOMER.USER.READ',
        'CUSTOMER.USER.LINK',
        'PRODUCT.READ',
        'CUSTOMER.PRICE_AS',
    ];

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function customerActors(): iterable
    {
        yield 'customer user' => [['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS];
        yield 'customer admin' => [['CUSTOMER_ADMIN'], self::CUSTOMER_ADMIN_PERMISSIONS];
        yield 'customer user holding every staff permission' => [
            ['CUSTOMER_USER'],
            [...self::CUSTOMER_ADMIN_PERMISSIONS, ...self::STAFF_ADMIN_PERMISSIONS, 'USER.READ', 'PRODUCT.UPDATE'],
        ];
        yield 'dual role: staff and customer user' => [
            ['EMPLOYEE', 'ADMINISTRATOR', 'CUSTOMER_USER'],
            [...self::STAFF_ADMIN_PERMISSIONS, 'USER.READ'],
        ];
        yield 'dual role: staff and customer admin' => [
            ['EMPLOYEE', 'CUSTOMER_ADMIN'],
            [...self::CUSTOMER_ADMIN_PERMISSIONS, ...self::STAFF_ADMIN_PERMISSIONS],
        ];
        yield 'lower-case customer role code' => [['customer_user'], self::CUSTOMER_USER_PERMISSIONS];
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    #[DataProvider('customerActors')]
    public function test_customer_actors_are_denied_staff_admin_routes(array $roles, array $permissions): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Isolation Co', 'ISO-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Isolation Contact', 'iso.contact@example.test');

        $this->setActor($app, $roles, $permissions);

        $probes = [
            ['GET', '/api/v1/admin/customers', null],
            ['GET', '/api/v1/admin/customers/' . $customerPublicId, null],
            ['POST', '/api/v1/admin/customers', ['customerName' => 'Injected Co']],
            ['PATCH', '/api/v1/admin/customers/' . $customerPublicId, ['customerName' => 'Renamed']],
            ['GET', '/api/v1/admin/customers/' . $customerPublicId . '/contacts', null],
            ['POST', '/api/v1/admin/customers/' . $customerPublicId . '/contacts', ['displayName' => 'Injected']],
            ['GET', '/api/v1/admin/customer-contacts/' . $contactPublicId, null],
            ['GET', '/api/v1/admin/customers/' . $customerPublicId . '/users', null],
            ['POST', '/api/v1/admin/customer-contacts/' . $contactPublicId . '/link-user', ['userPublicId' => self::ACTOR_PUBLIC_ID]],
            ['PATCH', '/api/v1/admin/customer-contacts/' . $contactPublicId . '/portal-access', ['enabled' => true]],
            ['GET', '/api/v1/admin/products', null],
            ['GET', '/api/v1/admin/product-lookups', null],
            ['GET', '/api/v1/admin/dashboard', null],
            ['GET', '/api/v1/admin/users', null],
        ];

        foreach ($probes as [$method, $path, $body]) {
            $response = $app->handle($this->request($method, $path, $body));
            $this->assertCustomerActorForbidden($response, $method . ' ' . $path);
        }
    }

    public function test_percent_encoded_admin_path_is_still_guarded(): void
    {
        $app = TestAppFactory::create();
        $this->setActor($app, ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/%61dmin/customers'));

        $this->assertCustomerActorForbidden($response, 'percent-encoded admin path');
    }

    public function test_customer_admin_cannot_link_self_to_another_company_contact(): void
    {
        $app = TestAppFactory::create();
        $ownCustomer = $this->createCustomer($app, 'Own Co', 'OWN-001');
        $ownContact = $this->createContact($app, $ownCustomer, 'Own Contact', 'own@example.test');
        $this->linkUser($app, $ownContact, self::ACTOR_PUBLIC_ID);
        $otherCustomer = $this->createCustomer($app, 'Other Co', 'OTHER-001');
        $otherContact = $this->createContact($app, $otherCustomer, 'Other Contact', 'other@example.test');

        $this->setActor($app, ['CUSTOMER_ADMIN'], self::CUSTOMER_ADMIN_PERMISSIONS);

        $link = $app->handle($this->request(
            'POST',
            '/api/v1/admin/customer-contacts/' . $otherContact . '/link-user',
            ['userPublicId' => self::ACTOR_PUBLIC_ID, 'isPrimary' => true],
        ));
        $this->assertCustomerActorForbidden($link, 'self-link to another company');

        $this->setActor($app, ['EMPLOYEE'], self::STAFF_ADMIN_PERMISSIONS);
        $users = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers/' . $otherCustomer . '/users'));
        self::assertSame(200, $users->getStatusCode(), (string) $users->getBody());
        self::assertSame([], $this->data($users)['items'], 'No link may have been written for the other company.');
    }

    public function test_customer_linked_to_several_companies_is_still_denied(): void
    {
        $app = TestAppFactory::create();
        foreach (['MULTI-A', 'MULTI-B'] as $code) {
            $customer = $this->createCustomer($app, 'Multi ' . $code, $code);
            $contact = $this->createContact($app, $customer, 'Multi Contact ' . $code, strtolower($code) . '@example.test');
            $this->linkUser($app, $contact, self::ACTOR_PUBLIC_ID);
        }

        $this->setActor($app, ['CUSTOMER_ADMIN'], self::CUSTOMER_ADMIN_PERMISSIONS);

        $this->assertCustomerActorForbidden(
            $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers')),
            'multi-company customer admin',
        );
    }

    public function test_role_change_to_customer_takes_effect_on_next_request(): void
    {
        $app = TestAppFactory::create();
        $this->setActor($app, ['EMPLOYEE'], self::STAFF_ADMIN_PERMISSIONS);
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers'))->getStatusCode());

        $this->setActor($app, ['EMPLOYEE', 'CUSTOMER_USER'], self::STAFF_ADMIN_PERMISSIONS);

        $this->assertCustomerActorForbidden(
            $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers')),
            'after customer role was added',
        );
    }

    public function test_staff_with_permissions_keep_admin_access(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Staff Access Co', 'STAFF-001');
        $this->setActor($app, ['EMPLOYEE'], self::STAFF_ADMIN_PERMISSIONS);

        foreach ([
            '/api/v1/admin/customers',
            '/api/v1/admin/customers/' . $customerPublicId,
            '/api/v1/admin/customers/' . $customerPublicId . '/contacts',
            '/api/v1/admin/products',
            '/api/v1/admin/product-lookups',
        ] as $path) {
            $response = $app->handle(TestAppFactory::request('GET', $path));
            self::assertSame(200, $response->getStatusCode(), $path . ': ' . (string) $response->getBody());
        }
    }

    public function test_staff_without_permission_still_get_permission_error_not_customer_error(): void
    {
        $app = TestAppFactory::create();
        $this->setActor($app, ['EMPLOYEE'], ['ACCOUNT.LOGIN']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('INSUFFICIENT_PERMISSION', $this->errorCode($response));
    }

    public function test_unauthenticated_admin_request_is_still_401(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $this->errorCode($response));
    }

    public function test_customer_read_alone_does_not_enable_staff_price_as(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Price Target Co', 'PRICE-001');
        $this->setActor($app, ['EMPLOYEE'], ['ACCOUNT.LOGIN', 'CUSTOMER.READ']);

        foreach ($this->priceAsProbes($customerPublicId) as [$method, $path, $body]) {
            $response = $app->handle($this->request($method, $path, $body));
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
            self::assertSame('FORBIDDEN', $this->errorCode($response));
        }
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    #[DataProvider('customerActors')]
    public function test_customer_actors_cannot_use_staff_price_as(array $roles, array $permissions): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Price Target Co', 'PRICE-002');
        $this->setActor($app, $roles, [...$permissions, 'CUSTOMER.PRICE_AS', 'PRODUCT.PRICING.OVERRIDE']);

        foreach ($this->priceAsProbes($customerPublicId) as [$method, $path, $body]) {
            $response = $app->handle($this->request($method, $path, $body));
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
        }
    }

    public function test_staff_with_price_as_can_select_customer_and_price_cart(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Staff Priced Co', 'PRICE-003');
        $this->setActor($app, ['EMPLOYEE'], ['ACCOUNT.LOGIN', 'CUSTOMER.PRICE_AS']);

        $search = $app->handle(TestAppFactory::request('GET', '/api/v1/pricing/customers?search=Staff'));
        self::assertSame(200, $search->getStatusCode(), (string) $search->getBody());

        $token = $this->selectCustomerToken($app, $customerPublicId);

        $cart = $app->handle(TestAppFactory::request('GET', '/api/v1/cart')->withHeader('X-Pricing-Context-Token', $token));
        self::assertSame(200, $cart->getStatusCode(), (string) $cart->getBody());
        self::assertSame(CustomerPricingContext::MODE_STAFF_SELECTED, $this->data($cart)['pricingMode'] ?? null);
    }

    public function test_staff_pricing_token_is_rejected_once_actor_becomes_customer(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Stale Token Co', 'PRICE-004');
        $this->setActor($app, ['EMPLOYEE'], ['ACCOUNT.LOGIN', 'CUSTOMER.PRICE_AS']);
        $token = $this->selectCustomerToken($app, $customerPublicId);

        $this->setActor($app, ['CUSTOMER_USER'], [...self::CUSTOMER_USER_PERMISSIONS, 'CUSTOMER.PRICE_AS']);

        foreach ([
            ['GET', '/api/v1/cart', null],
            ['POST', '/api/v1/cart/reprice', []],
        ] as [$method, $path, $body]) {
            $response = $app->handle($this->request($method, $path, $body)->withHeader('X-Pricing-Context-Token', $token));
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path . ': ' . (string) $response->getBody());
        }
    }

    public function test_customer_own_cart_pricing_still_works(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Own Pricing Co', 'OWNPRICE-001');
        $contact = $this->createContact($app, $customerPublicId, 'Own Pricing Contact', 'ownprice@example.test');
        $this->linkUser($app, $contact, self::ACTOR_PUBLIC_ID, true);

        $this->setActor($app, ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);

        $cart = $app->handle(TestAppFactory::request('GET', '/api/v1/cart'));
        self::assertSame(200, $cart->getStatusCode(), (string) $cart->getBody());
        self::assertNotSame(CustomerPricingContext::MODE_STAFF_SELECTED, $this->data($cart)['pricingMode'] ?? null);
    }

    public function test_customer_quotes_and_orders_remain_scoped_and_reachable(): void
    {
        $app = TestAppFactory::create(['QUOTES_ORDERS_LEGACY_READ_ENABLED' => 'true']);
        $customerPublicId = $this->createCustomer($app, 'Quote Owner Co', 'QOWN01');
        $contact = $this->createContact($app, $customerPublicId, 'Quote Contact', 'quote.contact@example.test');
        $this->linkUser($app, $contact, self::ACTOR_PUBLIC_ID, true);
        $this->bindQuotes($app);

        $this->setActor($app, ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);

        $list = $app->handle(TestAppFactory::request('GET', '/api/v1/quotes-orders?pageSize=10'));
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());

        $own = $app->handle(TestAppFactory::request('GET', '/api/v1/quotes/5001'));
        self::assertSame(200, $own->getStatusCode(), (string) $own->getBody());

        $foreign = $app->handle(TestAppFactory::request('GET', '/api/v1/quotes/5002'));
        self::assertContains($foreign->getStatusCode(), [403, 404], (string) $foreign->getBody());
    }

    public function test_public_catalogue_still_works_anonymously(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        foreach (['/api/v1/catalogue/tabs', '/api/v1/product-search?query=cable'] as $path) {
            $response = $app->handle(TestAppFactory::request('GET', $path));
            self::assertSame(200, $response->getStatusCode(), $path . ': ' . (string) $response->getBody());
        }
    }

    public function test_public_catalogue_still_works_for_signed_in_customer(): void
    {
        $app = TestAppFactory::create();
        $this->setActor($app, ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);

        foreach (['/api/v1/catalogue/tabs', '/api/v1/product-search?query=cable'] as $path) {
            $response = $app->handle(TestAppFactory::request('GET', $path));
            self::assertSame(200, $response->getStatusCode(), $path . ': ' . (string) $response->getBody());
        }
    }

    /**
     * @return list<array{string, string, array<string, mixed>|null}>
     */
    private function priceAsProbes(string $customerPublicId): array
    {
        return [
            ['GET', '/api/v1/pricing/customers?search=Price', null],
            ['GET', '/api/v1/pricing/customers/' . $customerPublicId . '/contacts', null],
            ['POST', '/api/v1/pricing/customer-context', ['customerId' => $customerPublicId]],
            ['POST', '/api/v1/pricing/margin-override', ['pricingContextToken' => 'synthetic-token', 'margin' => 10]],
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function selectCustomerToken(App $app, string $customerPublicId): string
    {
        $response = $app->handle($this->request('POST', '/api/v1/pricing/customer-context', ['customerId' => $customerPublicId]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $token = $this->data($response)['pricingContextToken'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private function assertCustomerActorForbidden(ResponseInterface $response, string $label): void
    {
        self::assertSame(403, $response->getStatusCode(), $label . ': ' . (string) $response->getBody());
        self::assertSame('CUSTOMER_ACTOR_FORBIDDEN', $this->errorCode($response), $label);
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
        self::assertIsArray($data);

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
     * @param App<ContainerInterface> $app
     */
    private function createContact(App $app, string $customerPublicId, string $displayName, string $email): string
    {
        $response = $app->handle($this->request(
            'POST',
            '/api/v1/admin/customers/' . $customerPublicId . '/contacts',
            ['displayName' => $displayName, 'email' => $email],
        ));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $publicId = $this->data($response)['publicId'] ?? null;
        self::assertIsString($publicId);

        return $publicId;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function linkUser(App $app, string $contactPublicId, string $userPublicId, bool $isPrimary = false): void
    {
        $response = $app->handle($this->request(
            'POST',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/link-user',
            ['userPublicId' => $userPublicId, 'isPrimary' => $isPrimary],
        ));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function setActor(App $app, array $roles, array $permissions): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);

        $repository->replacePermissions(1, array_values(array_unique($permissions)), $roles);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function bindQuotes(App $app): void
    {
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $repository = new InMemoryQuoteOrderRepository();
        foreach (['5001' => 'QOWN01', '5002' => 'QFOREIGN01'] as $quoteId => $customerCode) {
            $repository->seedQuote(new QuoteDetails(
                id: (string) $quoteId,
                type: QuoteOrderType::QUOTE,
                quoteId: (string) $quoteId,
                orderId: null,
                date: '2026-10-01',
                validDate: '2026-10-31',
                raisedBy: 'Synthetic',
                representative: 'Synthetic',
                customerCode: $customerCode,
                customerName: 'Synthetic ' . $customerCode,
                contactName: 'Synthetic Contact',
                reference: 'SYN-' . $quoteId,
                status: 'open',
                statusCode: '1710',
                statusLabel: 'Open',
                total: '10.00',
                totalStatus: 'resolved',
                currency: 'AUD',
            ));
        }
        $container->set(QuoteOrderRepository::class, $repository);
    }
}
