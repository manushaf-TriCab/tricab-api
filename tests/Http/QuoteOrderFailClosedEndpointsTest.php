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
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\QuoteOrder\Application\DTO\OrderDetails;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteDetails;
use TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;

/**
 * Quotes & Orders over HTTP: customer-oriented and unclassified identities
 * never fall through to staff-wide visibility, whatever their links.
 * Synthetic customers, quotes and orders only.
 */
final class QuoteOrderFailClosedEndpointsTest extends TestCase
{
    private const ACTOR_PUBLIC_ID = DevelopmentUserManagementCatalog::USER_PUBLIC_ID;

    private const OWN_CODE = 'QOWN01';
    private const SECOND_CODE = 'QSECOND01';
    private const FOREIGN_CODE = 'QFOREIGN01';

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

    private const STAFF_QUOTE_PERMISSIONS = ['ACCOUNT.LOGIN', 'QUOTE.READ'];

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonStaffRoleSets(): iterable
    {
        yield 'customer user' => [['CUSTOMER_USER']];
        yield 'customer admin' => [['CUSTOMER_ADMIN']];
        yield 'newbie with quote read granted by mistake' => [['NEWBIE']];
        yield 'guest with quote read granted by mistake' => [['GUEST']];
        yield 'customer user + employee' => [['CUSTOMER_USER', 'EMPLOYEE']];
        yield 'customer admin + administrator' => [['CUSTOMER_ADMIN', 'ADMINISTRATOR']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('nonStaffRoleSets')]
    public function test_without_links_lists_nothing_and_hides_every_record(array $roles): void
    {
        $app = $this->app();
        $this->setActor($app, $roles, self::CUSTOMER_USER_PERMISSIONS);

        self::assertSame([], $this->listedCodes($app));
        $this->assertHidden($app, '/api/v1/quotes/5001', 'QUOTE_NOT_FOUND');
        $this->assertHidden($app, '/api/v1/quotes/5002', 'QUOTE_NOT_FOUND');
        $this->assertHidden($app, '/api/v1/quotes/5002/document', 'QUOTE_NOT_FOUND');
        $this->assertHidden($app, '/api/v1/orders/9101', 'ORDER_NOT_FOUND');
        $this->assertHidden($app, '/api/v1/orders/9102', 'ORDER_NOT_FOUND');
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('nonStaffRoleSets')]
    public function test_with_one_link_sees_only_that_customer(array $roles): void
    {
        $app = $this->app();
        $this->linkActorTo($app, self::OWN_CODE);
        $this->setActor($app, $roles, self::CUSTOMER_USER_PERMISSIONS);

        self::assertSame([self::OWN_CODE], $this->listedCodes($app));
        self::assertSame(200, $this->get($app, '/api/v1/quotes/5001')->getStatusCode());
        self::assertSame(200, $this->get($app, '/api/v1/orders/9101')->getStatusCode());
        $this->assertHidden($app, '/api/v1/quotes/5002', 'QUOTE_NOT_FOUND');
        $this->assertHidden($app, '/api/v1/quotes/5002/document', 'QUOTE_NOT_FOUND');
        $this->assertHidden($app, '/api/v1/orders/9102', 'ORDER_NOT_FOUND');
        self::assertSame([], $this->listedCodes($app, '&customerCode=QFOREIGN'));
    }

    public function test_customer_linked_to_two_companies_sees_both_only(): void
    {
        $app = $this->app();
        $this->linkActorTo($app, self::OWN_CODE);
        $this->linkActorTo($app, self::SECOND_CODE);
        $this->setActor($app, ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);

        self::assertSame([self::OWN_CODE, self::SECOND_CODE], $this->listedCodes($app));
        self::assertSame(200, $this->get($app, '/api/v1/quotes/5003')->getStatusCode());
        $this->assertHidden($app, '/api/v1/quotes/5002', 'QUOTE_NOT_FOUND');
    }

    public function test_foreign_record_answers_exactly_like_a_missing_one(): void
    {
        $app = $this->app();
        $this->linkActorTo($app, self::OWN_CODE);
        $this->setActor($app, ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);

        foreach ([['/api/v1/quotes/', '5002'], ['/api/v1/quotes/', '5002', '/document'], ['/api/v1/orders/', '9102']] as $probe) {
            $suffix = $probe[2] ?? '';
            $foreign = $this->get($app, $probe[0] . $probe[1] . $suffix);
            $missing = $this->get($app, $probe[0] . '777777' . $suffix);

            self::assertSame($missing->getStatusCode(), $foreign->getStatusCode());
            self::assertSame(
                Payload::at(TestAppFactory::json($missing), 'error', 'code'),
                Payload::at(TestAppFactory::json($foreign), 'error', 'code'),
            );
            self::assertStringNotContainsString(self::FOREIGN_CODE, (string) $foreign->getBody());
        }
    }

    public function test_unlinked_customer_loses_access_on_the_next_request(): void
    {
        $app = $this->app();
        $contact = $this->linkActorTo($app, self::OWN_CODE);

        $this->setActor($app, ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);
        self::assertSame([self::OWN_CODE], $this->listedCodes($app));

        $this->setActor($app, ['ADMINISTRATOR'], ['ACCOUNT.LOGIN', 'CUSTOMER.USER.UNLINK']);
        $unlink = $app->handle(TestAppFactory::request(
            'DELETE',
            '/api/v1/admin/customer-contacts/' . $contact . '/link-user/' . self::ACTOR_PUBLIC_ID,
        ));
        self::assertLessThan(300, $unlink->getStatusCode(), (string) $unlink->getBody());

        $this->setActor($app, ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);
        self::assertSame([], $this->listedCodes($app));
        $this->assertHidden($app, '/api/v1/quotes/5001', 'QUOTE_NOT_FOUND');
        $this->assertHidden($app, '/api/v1/quotes/5002', 'QUOTE_NOT_FOUND');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function staffRoleSets(): iterable
    {
        yield 'employee' => [['EMPLOYEE']];
        yield 'administrator' => [['ADMINISTRATOR']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoleSets')]
    public function test_staff_keep_access_to_every_customer(array $roles): void
    {
        $app = $this->app();
        $this->setActor($app, $roles, self::STAFF_QUOTE_PERMISSIONS);

        self::assertSame([self::FOREIGN_CODE, self::OWN_CODE, self::SECOND_CODE], $this->listedCodes($app));
        self::assertSame(200, $this->get($app, '/api/v1/quotes/5002')->getStatusCode());
        self::assertSame(200, $this->get($app, '/api/v1/orders/9102')->getStatusCode());
    }

    public function test_act_as_a_customer_without_links_sees_nothing(): void
    {
        $app = $this->app();
        $target = $this->register($app, 'quote-customer-unlinked', ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);
        $session = $this->startActAs($app, $target);

        self::assertSame([], $this->listedCodes($app, '', $session));
        $this->assertHidden($app, '/api/v1/quotes/5002', 'QUOTE_NOT_FOUND', $session);
        $this->assertHidden($app, '/api/v1/orders/9102', 'ORDER_NOT_FOUND', $session);

        self::assertSame(
            [self::FOREIGN_CODE, self::OWN_CODE, self::SECOND_CODE],
            $this->listedCodes($app),
            'the administrator outside the session keeps staff access',
        );
    }

    public function test_act_as_a_linked_customer_sees_only_that_customer(): void
    {
        $app = $this->app();
        $target = $this->register($app, 'quote-customer-linked', ['CUSTOMER_USER'], self::CUSTOMER_USER_PERMISSIONS);
        $this->linkTargetTo($app, $target, self::OWN_CODE);
        $session = $this->startActAs($app, $target);

        self::assertSame([self::OWN_CODE], $this->listedCodes($app, '', $session));
        self::assertSame(200, $this->get($app, '/api/v1/quotes/5001', $session)->getStatusCode());
        $this->assertHidden($app, '/api/v1/quotes/5002', 'QUOTE_NOT_FOUND', $session);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(): App
    {
        $app = TestAppFactory::create(['QUOTES_ORDERS_LEGACY_READ_ENABLED' => 'true']);
        $this->bindQuotes($app);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<string>
     */
    private function listedCodes(App $app, string $query = '', ?string $actAsSession = null): array
    {
        $response = $this->get($app, '/api/v1/quotes-orders?pageSize=100&type=all' . $query, $actAsSession);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $codes = [];
        foreach (Payload::list(Payload::at(TestAppFactory::json($response), 'data', 'items')) as $item) {
            $codes[] = Payload::str(Payload::obj($item)['customerCode']);
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function assertHidden(App $app, string $path, string $errorCode, ?string $actAsSession = null): void
    {
        $response = $this->get($app, $path, $actAsSession);
        self::assertSame(404, $response->getStatusCode(), $path . ': ' . (string) $response->getBody());
        self::assertSame($errorCode, Payload::at(TestAppFactory::json($response), 'error', 'code'), $path);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function get(App $app, string $path, ?string $actAsSession = null): ResponseInterface
    {
        $request = TestAppFactory::request('GET', $path);
        if ($actAsSession !== null) {
            $request = $request->withHeader('X-Act-As-Session', $actAsSession);
        }

        return $app->handle($request);
    }

    /**
     * Links the development actor to a new synthetic customer. Runs as the
     * default development administrator, so call it before setActor().
     *
     * @param App<ContainerInterface> $app
     */
    private function linkActorTo(App $app, string $customerCode): string
    {
        $contactPublicId = $this->createContact($app, $customerCode);
        $link = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/link-user',
            ['userPublicId' => self::ACTOR_PUBLIC_ID, 'isPrimary' => true],
        ));
        self::assertSame(201, $link->getStatusCode(), (string) $link->getBody());

        return $contactPublicId;
    }

    /**
     * Act-as targets live only in the authentication user store, which the
     * admin link endpoint does not consult, so the link is stored directly.
     *
     * @param App<ContainerInterface> $app
     */
    private function linkTargetTo(App $app, User $target, string $customerCode): void
    {
        $contactPublicId = $this->createContact($app, $customerCode);
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $contacts = $container->get(CustomerContactRepository::class);
        self::assertInstanceOf(CustomerContactRepository::class, $contacts);
        $links = $container->get(CustomerContactUserRepository::class);
        self::assertInstanceOf(CustomerContactUserRepository::class, $links);

        $contactId = $contacts->findIdByPublicId(CustomerContactPublicId::fromString($contactPublicId));
        self::assertNotNull($contactId);
        $links->link($contactId, $target->id(), true, null);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createContact(App $app, string $customerCode): string
    {
        $customer = $app->handle($this->jsonRequest('POST', '/api/v1/admin/customers', [
            'customerName' => 'Synthetic ' . $customerCode,
            'customerCode' => $customerCode,
            'countryCode' => 'AU',
        ]));
        self::assertSame(201, $customer->getStatusCode(), (string) $customer->getBody());
        $customerPublicId = Payload::str(Payload::at(TestAppFactory::json($customer), 'data', 'publicId'));

        $contact = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customers/' . $customerPublicId . '/contacts',
            ['displayName' => 'Contact ' . $customerCode, 'email' => strtolower($customerCode) . '@example.test'],
        ));
        self::assertSame(201, $contact->getStatusCode(), (string) $contact->getBody());

        return Payload::str(Payload::at(TestAppFactory::json($contact), 'data', 'publicId'));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function startActAs(App $app, User $target): string
    {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/act-as', [
            'userPublicId' => $target->publicId()->toString(),
            'reason' => 'Synthetic quote isolation check',
        ]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return Payload::str(Payload::at(TestAppFactory::json($response), 'data', 'sessionId'));
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
                ExternalTenantId::fromString('development-tenant'),
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
     * @param App<ContainerInterface> $app
     */
    private function userRepository(App $app): InMemoryApiUserRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);

        return $repository;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function bindQuotes(App $app): void
    {
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $repository = new InMemoryQuoteOrderRepository();
        $repository->seedQuote($this->quote('5001', self::OWN_CODE));
        $repository->seedQuote($this->quote('5002', self::FOREIGN_CODE));
        $repository->seedQuote($this->quote('5003', self::SECOND_CODE));
        $repository->seedOrder($this->order('9101', $this->quote('5011', self::OWN_CODE, '9101')));
        $repository->seedOrder($this->order('9102', $this->quote('5012', self::FOREIGN_CODE, '9102')));
        $container->set(QuoteOrderRepository::class, $repository);
    }

    private function quote(string $quoteId, string $customerCode, ?string $orderId = null): QuoteDetails
    {
        return new QuoteDetails(
            id: $quoteId,
            type: QuoteOrderType::QUOTE,
            quoteId: $quoteId,
            orderId: $orderId,
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
        );
    }

    private function order(string $orderId, QuoteDetails $quote): OrderDetails
    {
        return new OrderDetails(
            id: $orderId,
            orderId: $orderId,
            customerPoNumber: 'PO-' . $orderId,
            reference: null,
            issueDate: '2026-10-02',
            comments: null,
            quoteId: $quote->quoteId,
            quote: $quote,
        );
    }
}
