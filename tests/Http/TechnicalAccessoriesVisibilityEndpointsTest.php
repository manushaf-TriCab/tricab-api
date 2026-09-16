<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

final class TechnicalAccessoriesVisibilityEndpointsTest extends TestCase
{
    private const THILAN_PUBLIC_ID = DevelopmentUserManagementCatalog::USER_PUBLIC_ID;

    public function test_employee_sees_accessories_section(): void
    {
        $app = TestAppFactory::create();
        $payload = $this->sections($app);

        $ids = array_map(static fn (array $s): string => Payload::str($s['id']), Payload::objList($payload['sections']));
        self::assertContains('accessories', $ids);
        $accessories = $this->sectionById($payload, 'accessories');
        self::assertSame('staff_or_sw_digit_market', $accessories['visibilityPolicy']);
        self::assertArrayNotHasKey('visibilityNote', $accessories);
    }

    public function test_sw0_customer_sees_accessories(): void
    {
        $app = $this->customerWorld('SW0');
        $payload = $this->sections($app);
        self::assertContains('accessories', array_column(Payload::objList($payload['sections']), 'id'));
    }

    public function test_sw3_customer_sees_accessories(): void
    {
        $app = $this->customerWorld('SW3');
        $payload = $this->sections($app);
        self::assertContains('accessories', array_column(Payload::objList($payload['sections']), 'id'));
    }

    public function test_sw5_customer_sees_accessories(): void
    {
        $app = $this->customerWorld('SW5');
        $payload = $this->sections($app);
        self::assertContains('accessories', array_column(Payload::objList($payload['sections']), 'id'));
    }

    public function test_non_sw_market_hides_accessories(): void
    {
        $app = $this->customerWorld('ENERGY');
        $payload = $this->sections($app);
        $ids = array_column(Payload::objList($payload['sections']), 'id');
        self::assertNotContains('accessories', $ids);
        self::assertContains('general', $ids);
        self::assertContains('industrial', $ids);
        self::assertContains('sbo', $ids);
    }

    public function test_no_market_hides_accessories(): void
    {
        $app = $this->customerWorld(null);
        $payload = $this->sections($app);
        self::assertNotContains('accessories', array_column(Payload::objList($payload['sections']), 'id'));
    }

    public function test_multi_customer_with_sw_market_sees_accessories(): void
    {
        $app = TestAppFactory::create();
        $energy = $this->createCustomer($app, 'Energy Co', 'ENERGY-MULTI', 'ENERGY');
        $sw = $this->createCustomer($app, 'SW Co', 'SW-MULTI', 'SW1');
        $energyContact = $this->createContact($app, $energy, 'Energy Contact', 'energy.multi@example.com');
        $swContact = $this->createContact($app, $sw, 'SW Contact', 'sw.multi@example.com');
        $this->linkUser($app, $energyContact, self::THILAN_PUBLIC_ID);
        $this->linkUser($app, $swContact, self::THILAN_PUBLIC_ID);
        $this->asCustomerUser($app);

        $me = $this->me($app);
        self::assertContains('SW1', Payload::list(Payload::obj($me['customerContext'])['marketCodes']));
        self::assertContains('ENERGY', Payload::list(Payload::obj($me['customerContext'])['marketCodes']));

        $payload = $this->sections($app);
        self::assertContains('accessories', array_column(Payload::objList($payload['sections']), 'id'));
    }

    public function test_me_exposes_market_codes_for_linked_customer(): void
    {
        $app = $this->customerWorld('SW4');
        $me = $this->me($app);
        self::assertSame(['SW4'], Payload::obj($me['customerContext'])['marketCodes']);
    }

    public function test_unauthenticated_technical_still_requires_auth(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/sections'));
        $payload = TestAppFactory::json($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', Payload::obj($payload['error'])['code']);
    }

    public function test_unauthorized_accessories_product_coding_is_not_found(): void
    {
        $app = $this->customerWorld('ENERGY');
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/product-coding/accessories'));
        $payload = TestAppFactory::json($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('TECHNICAL_PRODUCT_CODING_NOT_FOUND', Payload::obj($payload['error'])['code']);
    }

    public function test_authorized_accessories_product_coding_ok(): void
    {
        $app = $this->customerWorld('SW2');
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/product-coding/accessories'));
        $payload = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('accessories', Payload::obj($payload['data'])['mode']);
    }

    public function test_unauthorized_accessory_document_category_is_not_found(): void
    {
        $app = $this->customerWorld('CONSULT1');
        foreach (['accessory-certs', 'accessory-warranty', 'installation-guides'] as $category) {
            $response = $app->handle(
                TestAppFactory::request('GET', '/api/v1/technical/documents?category=' . $category),
            );
            $payload = TestAppFactory::json($response);
            self::assertSame(404, $response->getStatusCode(), $category);
            self::assertSame('TECHNICAL_DOCUMENTS_NOT_FOUND', Payload::obj($payload['error'])['code'], $category);
        }
    }

    public function test_unauthorized_documents_catalog_omits_accessory_families(): void
    {
        $app = $this->customerWorld('ENERGY');
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/documents'));
        $payload = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode());
        $families = Payload::obj(Payload::obj($payload['data'])['families']);
        self::assertArrayNotHasKey('accessory-certs', $families);
        self::assertArrayNotHasKey('accessory-warranty', $families);
        self::assertArrayNotHasKey('installation-guides', $families);
        self::assertArrayHasKey('bep-pvc-disposal', $families);
    }

    public function test_industrial_product_coding_unaffected_for_non_sw_customer(): void
    {
        $app = $this->customerWorld('ENERGY');
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/product-coding/industrial'));
        $payload = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('industrial', Payload::obj($payload['data'])['mode']);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function customerWorld(?string $marketCode): App
    {
        $app = TestAppFactory::create();
        $customer = $this->createCustomer(
            $app,
            'Portal Customer ' . ($marketCode ?? 'NONE'),
            'PORTAL-' . ($marketCode ?? 'NONE'),
            $marketCode,
        );
        $contact = $this->createContact(
            $app,
            $customer,
            'Portal Contact',
            'portal.' . strtolower((string) ($marketCode ?? 'none')) . '@example.com',
        );
        $this->linkUser($app, $contact, self::THILAN_PUBLIC_ID);
        $this->asCustomerUser($app);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function asCustomerUser(App $app): void
    {
        $this->userRepository($app)->replacePermissions(1, ['ACCOUNT.LOGIN'], ['CUSTOMER_USER']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed>
     */
    private function sections(App $app): array
    {
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/technical/sections'));
        $payload = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        return $data;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed>
     */
    private function me(App $app): array
    {
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/me'));
        $payload = TestAppFactory::json($response);
        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sectionById(array $payload, string $id): array
    {
        foreach (Payload::objList($payload['sections']) as $section) {
            if (($section['id'] ?? null) === $id) {
                return $section;
            }
        }
        self::fail('Section not found: ' . $id);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createCustomer(App $app, string $customerName, string $customerCode, ?string $marketCode): string
    {
        $body = [
            'customerName' => $customerName,
            'customerCode' => $customerCode,
        ];
        if ($marketCode !== null) {
            $body['marketCode'] = $marketCode;
        }

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/customers', $body));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = TestAppFactory::json($response);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        return Payload::str($data['publicId']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createContact(App $app, string $customerPublicId, string $displayName, string $email): string
    {
        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customers/' . $customerPublicId . '/contacts',
            ['displayName' => $displayName, 'email' => $email],
        ));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = TestAppFactory::json($response);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        return Payload::str($data['publicId']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function linkUser(App $app, string $contactPublicId, string $userPublicId): void
    {
        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/customer-contacts/' . $contactPublicId . '/link-user',
            ['userPublicId' => $userPublicId, 'isPrimary' => true],
        ));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
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
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }
}
