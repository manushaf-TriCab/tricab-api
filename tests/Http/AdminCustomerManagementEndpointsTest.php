<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

final class AdminCustomerManagementEndpointsTest extends TestCase
{
    private const THILAN_PUBLIC_ID = DevelopmentUserManagementCatalog::USER_PUBLIC_ID;

    // Well-formed v4 UUID (passes CustomerPublicId's format validation) that
    // is never seeded, so lookups against it exercise the 404 path.
    private const NONEXISTENT_PUBLIC_ID = '22222222-2222-4222-8222-222222222222';

    public function test_admin_customer_routes_require_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($payload['success']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('UNAUTHENTICATED', $error['code']);
    }

    public function test_admin_customer_routes_require_permission(): void
    {
        $app = TestAppFactory::create();
        $this->stripAdminPermissions($app);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_create_customer_success(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/customers', [
            'customerName' => 'Acme Logistics',
            'customerCode' => 'ACME-001',
            'countryCode' => 'AU',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('Acme Logistics', $data['customerName']);
        self::assertSame('ACME-001', $data['customerCode']);
        self::assertSame('active', $data['status']);
        self::assertIsString($data['publicId']);
        self::assertArrayNotHasKey('customerId', $data);
        self::assertArrayNotHasKey('id', $data);
    }

    public function test_create_customer_validates_required_fields(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/customers', [
            'customerCode' => 'NO-NAME',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('VALIDATION_FAILED', $error['code']);
    }

    public function test_create_customer_rejects_duplicate_code(): void
    {
        $app = TestAppFactory::create();
        $this->createCustomer($app, 'First Customer', 'DUP-CODE');

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/customers', [
            'customerName' => 'Second Customer',
            'customerCode' => 'DUP-CODE',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(409, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('CUSTOMER_CODE_CONFLICT', $error['code']);
    }

    public function test_list_customers_returns_items_pagination_and_counts(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createCustomer($app, 'Listed Customer', 'LIST-001');

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['items']);
        self::assertIsArray($data['pagination']);
        self::assertIsArray($data['counts']);

        /** @var list<array<string, mixed>> $items */
        $items = $data['items'];
        $customer = self::findByPublicId($items, $publicId);
        self::assertNotNull($customer);
        self::assertArrayNotHasKey('customerId', $customer);

        /** @var array<string, mixed> $counts */
        $counts = $data['counts'];
        self::assertGreaterThanOrEqual(1, $counts['totalCustomers']);
    }

    public function test_get_customer_returns_details(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createCustomer($app, 'Detail Customer', 'DETAIL-001');

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/customers/' . $publicId));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame($publicId, $data['publicId']);
        self::assertSame('Detail Customer', $data['customerName']);
        self::assertArrayNotHasKey('customerId', $data);
    }

    public function test_get_customer_not_found(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/customers/' . self::NONEXISTENT_PUBLIC_ID),
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(404, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('CUSTOMER_NOT_FOUND', $error['code']);
    }

    public function test_update_customer_success(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createCustomer($app, 'Original Name', 'UPDATE-001');

        $response = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/customers/' . $publicId, [
            'customerName' => 'Updated Name',
            'suburb' => 'Docklands',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('Updated Name', $data['customerName']);
        self::assertSame('Docklands', $data['suburb']);
    }

    public function test_change_customer_status_success(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createCustomer($app, 'Status Customer', 'STATUS-001');

        $response = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/customers/' . $publicId . '/status',
            ['status' => 'inactive'],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('inactive', $data['status']);
        self::assertFalse($data['isActive']);
    }

    public function test_create_customer_contact_success(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Contact Owner', 'CONTACT-001');

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customers/' . $customerPublicId . '/contacts',
            ['displayName' => 'Jane Doe', 'email' => 'jane.doe@example.com'],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('Jane Doe', $data['displayName']);
        self::assertSame($customerPublicId, $data['customerPublicId']);
        self::assertIsString($data['publicId']);
        self::assertArrayNotHasKey('customerContactId', $data);
    }

    public function test_create_customer_contact_requires_permission(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Locked Owner', 'LOCKED-001');
        // Grant CUSTOMER.READ (for the customer creation to have worked above)
        // but strip contact-create so this call is denied specifically.
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', 'CUSTOMER.READ', 'CUSTOMER.UPDATE']);

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customers/' . $customerPublicId . '/contacts',
            ['displayName' => 'Should Fail'],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_list_customer_contacts_returns_items_and_pagination(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Contacts Owner', 'CONTACTS-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'List Contact', 'list.contact@example.com');

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/customers/' . $customerPublicId . '/contacts'),
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['items']);
        self::assertIsArray($data['pagination']);

        /** @var list<array<string, mixed>> $items */
        $items = $data['items'];
        $contact = self::findByPublicId($items, $contactPublicId);
        self::assertNotNull($contact);
    }

    public function test_get_customer_contact_returns_details(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Get Contact Owner', 'GETCONTACT-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Get Contact', 'get.contact@example.com');

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/customer-contacts/' . $contactPublicId),
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame($contactPublicId, $data['publicId']);
        self::assertSame('Get Contact', $data['displayName']);
    }

    public function test_update_customer_contact_success(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Update Contact Owner', 'UPDCONTACT-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Original Contact', 'original@example.com');

        $response = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/customer-contacts/' . $contactPublicId,
            ['displayName' => 'Renamed Contact'],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('Renamed Contact', $data['displayName']);
    }

    public function test_change_customer_contact_status_success(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Status Contact Owner', 'STATUSCONTACT-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Status Contact', 'status.contact@example.com');

        $response = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/status',
            ['isActive' => false],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertFalse($data['isActive']);
    }

    public function test_create_customer_portal_user_success(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Portal Owner', 'PORTAL-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Portal Contact', 'portal.contact@example.com');

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/portal-user',
            ['roleCode' => 'CUSTOMER_USER'],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('portal.contact@example.com', $data['emailAddress']);
        self::assertIsString($data['userPublicId']);
        /** @var array<string, mixed> $contact */
        $contact = $data['contact'];
        self::assertSame('active', $contact['portalAccessStatus']);
        /** @var array<string, mixed> $link */
        $link = $data['link'];
        self::assertTrue($link['isPrimary']);
        self::assertTrue($link['isActive']);
    }

    public function test_create_customer_portal_user_rejects_non_customer_facing_role(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Bad Role Owner', 'BADROLE-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Bad Role Contact', 'bad.role@example.com');

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/portal-user',
            ['roleCode' => 'ADMINISTRATOR'],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('VALIDATION_FAILED', $error['code']);
    }

    public function test_link_existing_user_to_contact(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Link Owner', 'LINK-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Link Contact', 'link.contact@example.com');

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/link-user',
            ['userPublicId' => self::THILAN_PUBLIC_ID, 'isPrimary' => true],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame($contactPublicId, $data['contactPublicId']);
        self::assertTrue($data['isPrimary']);
        self::assertTrue($data['isActive']);
    }

    public function test_link_user_not_found(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Link 404 Owner', 'LINK404-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Link 404 Contact', 'link404@example.com');

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/link-user',
            ['userPublicId' => self::NONEXISTENT_PUBLIC_ID],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(404, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('USER_NOT_FOUND', $error['code']);
    }

    public function test_list_customer_portal_users(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Users Owner', 'USERS-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Users Contact', 'users.contact@example.com');

        $this->linkUser($app, $contactPublicId, self::THILAN_PUBLIC_ID, true);

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/customers/' . $customerPublicId . '/users'),
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $data['items'];
        self::assertNotEmpty($items);
        self::assertSame($contactPublicId, $items[0]['contactPublicId']);
    }

    public function test_list_customer_portal_users_customer_not_found(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/customers/' . self::NONEXISTENT_PUBLIC_ID . '/users'),
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(404, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('CUSTOMER_NOT_FOUND', $error['code']);
    }

    public function test_unlink_customer_contact_user(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Unlink Owner', 'UNLINK-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Unlink Contact', 'unlink.contact@example.com');
        $this->linkUser($app, $contactPublicId, self::THILAN_PUBLIC_ID, true);

        $response = $app->handle(TestAppFactory::request(
            'DELETE',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/link-user/' . self::THILAN_PUBLIC_ID,
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertTrue($data['unlinked']);

        $listResponse = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/customers/' . $customerPublicId . '/users'),
        );
        $listPayload = TestAppFactory::json($listResponse);
        /** @var array<string, mixed> $listData */
        $listData = $listPayload['data'];
        self::assertSame([], $listData['items']);
    }

    public function test_change_customer_portal_access_enable_and_disable(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Access Owner', 'ACCESS-001');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Access Contact', 'access.contact@example.com');

        // A freshly created contact starts "not-invited", which per
        // PortalAccessPolicy can only move to "invited" or "declined" next —
        // reaching "active" requires passing through "invited" first.
        $inviteResponse = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/portal-access',
            ['enabled' => true, 'activateImmediately' => false],
        ));
        $invitePayload = TestAppFactory::json($inviteResponse);

        self::assertSame(200, $inviteResponse->getStatusCode(), (string) $inviteResponse->getBody());
        /** @var array<string, mixed> $inviteData */
        $inviteData = $invitePayload['data'];
        self::assertSame('invited', $inviteData['portalAccessStatus']);

        $enableResponse = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/portal-access',
            ['enabled' => true, 'activateImmediately' => true],
        ));
        $enablePayload = TestAppFactory::json($enableResponse);

        self::assertSame(200, $enableResponse->getStatusCode(), (string) $enableResponse->getBody());
        /** @var array<string, mixed> $enableData */
        $enableData = $enablePayload['data'];
        self::assertSame('active', $enableData['portalAccessStatus']);

        $disableResponse = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/portal-access',
            ['enabled' => false],
        ));
        $disablePayload = TestAppFactory::json($disableResponse);

        self::assertSame(200, $disableResponse->getStatusCode(), (string) $disableResponse->getBody());
        /** @var array<string, mixed> $disableData */
        $disableData = $disablePayload['data'];
        self::assertSame('suspended', $disableData['portalAccessStatus']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createCustomer(App $app, string $customerName, ?string $customerCode = null): string
    {
        $body = ['customerName' => $customerName];
        if ($customerCode !== null) {
            $body['customerCode'] = $customerCode;
        }

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/customers', $body));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = TestAppFactory::json($response);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['publicId']);

        return $data['publicId'];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createContact(App $app, string $customerPublicId, string $displayName, ?string $email = null): string
    {
        $body = ['displayName' => $displayName];
        if ($email !== null) {
            $body['email'] = $email;
        }

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customers/' . $customerPublicId . '/contacts',
            $body,
        ));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = TestAppFactory::json($response);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['publicId']);

        return $data['publicId'];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function linkUser(App $app, string $contactPublicId, string $userPublicId, bool $isPrimary = false): void
    {
        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/link-user',
            ['userPublicId' => $userPublicId, 'isPrimary' => $isPrimary],
        ));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function stripAdminPermissions(App $app): void
    {
        $repository = $this->userRepository($app);
        $repository->replacePermissions(1, ['account.login'], ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $repository = $this->userRepository($app);
        $repository->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
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
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private static function findByPublicId(array $items, string $publicId): ?array
    {
        foreach ($items as $item) {
            if (($item['publicId'] ?? null) === $publicId) {
                return $item;
            }
        }

        return null;
    }
}
