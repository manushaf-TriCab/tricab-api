<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\LogNote\Persistence\DisabledCustomerNoteExternalSync;
use TriCab\Api\LogNote\Persistence\InMemoryLogNoteAuditRecorder;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\LogNote\Contract\CustomerNoteExternalSync;
use TriCab\Core\LogNote\Contract\LogNoteAuditRecorder;

final class LogNoteEndpointsTest extends TestCase
{
    private const NONEXISTENT_PUBLIC_ID = '22222222-2222-4222-8222-222222222222';

    public function test_log_note_routes_require_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/log-notes'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($payload['success']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('UNAUTHENTICATED', $error['code']);
    }

    public function test_read_requires_customer_note_read_permission(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/log-notes'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_create_requires_customer_note_create_permission(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', 'CUSTOMER_NOTE.READ', 'CUSTOMER.UPDATE', 'CUSTOMER.READ']);
        $customerPublicId = $this->createCustomer($app, 'Denied Notes Customer', 'DN-001');

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => $customerPublicId,
            'interactionType' => 'Phone Call',
            'category' => 'standard',
            'body' => 'Should not save',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_create_requires_customer_and_rejects_invalid_customer(): void
    {
        $app = TestAppFactory::create();

        $missingCustomer = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'interactionType' => 'Phone Call',
            'category' => 'standard',
            'body' => 'No customer',
        ]));
        self::assertSame(422, $missingCustomer->getStatusCode());

        $invalidCustomer = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => self::NONEXISTENT_PUBLIC_ID,
            'interactionType' => 'Phone Call',
            'category' => 'standard',
            'body' => 'Unknown customer',
        ]));
        $payload = TestAppFactory::json($invalidCustomer);
        self::assertSame(404, $invalidCustomer->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('CUSTOMER_NOT_FOUND', $error['code']);
    }

    public function test_create_validates_interaction_type_category_and_body(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Validation Customer', 'VAL-001');

        $type = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => $customerPublicId,
            'interactionType' => 'Fax',
            'category' => 'standard',
            'body' => 'Hello',
        ]));
        self::assertSame(422, $type->getStatusCode());

        $category = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => $customerPublicId,
            'interactionType' => 'Phone Call',
            'category' => 'secret',
            'body' => 'Hello',
        ]));
        self::assertSame(422, $category->getStatusCode());

        $body = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => $customerPublicId,
            'interactionType' => 'Phone Call',
            'category' => 'standard',
            'body' => '   ',
        ]));
        self::assertSame(422, $body->getStatusCode());
    }

    public function test_create_rejects_contact_customer_mismatch_and_client_identity(): void
    {
        $app = TestAppFactory::create();
        $customerA = $this->createCustomer($app, 'Customer A', 'CA-001');
        $customerB = $this->createCustomer($app, 'Customer B', 'CB-001');
        $contactB = $this->createContact($app, $customerB, 'Pat Lee');

        $mismatch = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => $customerA,
            'contactPublicId' => $contactB,
            'interactionType' => 'Phone Call',
            'category' => 'standard',
            'body' => 'Wrong contact',
        ]));
        $mismatchPayload = TestAppFactory::json($mismatch);
        self::assertSame(422, $mismatch->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $mismatchPayload['error'];
        self::assertSame('CONTACT_CUSTOMER_MISMATCH', $error['code']);

        $identity = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => $customerA,
            'interactionType' => 'Phone Call',
            'category' => 'standard',
            'body' => 'Spoofed author',
            'createdBy' => 'someone-else',
        ]));
        $identityPayload = TestAppFactory::json($identity);
        self::assertSame(422, $identity->getStatusCode());
        /** @var array<string, mixed> $identityError */
        $identityError = $identityPayload['error'];
        self::assertSame('CLIENT_IDENTITY_REJECTED', $identityError['code']);
    }

    public function test_create_uses_authenticated_creator_and_does_not_invoke_4js(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Acme Notes', 'ACME-LN');
        $contactPublicId = $this->createContact($app, $customerPublicId, 'Alex Kim', 'Purchasing');

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => $customerPublicId,
            'contactPublicId' => $contactPublicId,
            'interactionType' => 'Phone Call',
            'category' => 'niche',
            'body' => 'Discussed BV cable pricing and a follow-up quote.',
            'isAiGenerated' => true,
            'followUpAt' => '2026-09-20T10:00:00Z',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertTrue($data['isAiGenerated']);
        self::assertSame('niche', $data['category']);
        self::assertSame('Margin', $data['categoryLabel']);
        self::assertSame('Phone Call', $data['interactionType']);
        self::assertSame(DevelopmentUserManagementCatalog::USER_PUBLIC_ID, $data['createdByUserPublicId']);
        self::assertSame(DevelopmentUserManagementCatalog::USER_DISPLAY_NAME, $data['createdByDisplayName']);
        self::assertSame('Alex Kim', $data['contactName']);
        self::assertArrayNotHasKey('createdByUserId', $data);

        $sync = $this->externalSync($app);
        self::assertSame(0, $sync->invocationCount());

        $audit = $this->audit($app);
        self::assertNotEmpty($audit->events());
        self::assertSame('CUSTOMER_NOTE_CREATED', $audit->events()[0]['action']);
        self::assertSame($data['publicId'], $audit->events()[0]['note']);
        self::assertSame($customerPublicId, $audit->events()[0]['customer']);
    }

    public function test_list_filters_search_pagination_and_newest_first(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Listed Notes Co', 'LIST-LN', 'AU', 'WEST');
        $otherCustomer = $this->createCustomer($app, 'Other Notes Co', 'OTHER-LN', 'NZ', 'NORTH');

        $first = $this->createNote($app, $customerPublicId, 'First note about copper', 'Phone Call', 'standard');
        $second = $this->createNote($app, $customerPublicId, 'Second note about aluminium', 'Conference', 'accounts');
        $this->createNote($app, $otherCustomer, 'Unrelated', 'Customer', 'standard');

        $list = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/log-notes?customerId=' . $customerPublicId . '&page=1&pageSize=1',
        ));
        $listPayload = TestAppFactory::json($list);
        self::assertSame(200, $list->getStatusCode());
        $data = $this->listPayload($listPayload);
        self::assertCount(1, $data['items']);
        self::assertSame($second, $this->itemPublicId($data['items'][0] ?? null));
        self::assertSame(2, $data['pagination']['totalItems']);
        self::assertSame(2, $data['pagination']['totalPages']);

        $search = $app->handle(TestAppFactory::request('GET', '/api/v1/log-notes?search=copper'));
        $searchData = $this->listPayload(TestAppFactory::json($search));
        self::assertCount(1, $searchData['items']);
        self::assertSame($first, $this->itemPublicId($searchData['items'][0] ?? null));

        $type = $app->handle(TestAppFactory::request('GET', '/api/v1/log-notes?interactionType=Conference'));
        $typeData = $this->listPayload(TestAppFactory::json($type));
        self::assertCount(1, $typeData['items']);
        self::assertSame($second, $this->itemPublicId($typeData['items'][0] ?? null));

        $country = $app->handle(TestAppFactory::request('GET', '/api/v1/log-notes?country=AU&territory=WEST'));
        $countryData = $this->listPayload(TestAppFactory::json($country));
        self::assertGreaterThanOrEqual(2, $countryData['pagination']['totalItems']);

        $customerRoute = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/customers/' . $customerPublicId . '/log-notes',
        ));
        $customerData = $this->listPayload(TestAppFactory::json($customerRoute));
        self::assertSame(200, $customerRoute->getStatusCode());
        self::assertSame(2, $customerData['pagination']['totalItems']);
    }

    public function test_detail_and_date_filter_and_created_by(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'Detail Notes Co', 'DET-LN');
        $publicId = $this->createNote($app, $customerPublicId, 'Detail body', 'Online Meeting', 'standard');

        $detail = $app->handle(TestAppFactory::request('GET', '/api/v1/log-notes/' . $publicId));
        $detailPayload = TestAppFactory::json($detail);
        self::assertSame(200, $detail->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $detailPayload['data'];
        self::assertSame('Detail body', $data['body']);
        self::assertSame('Online Meeting', $data['interactionTypeLabel']);

        $missing = $app->handle(TestAppFactory::request('GET', '/api/v1/log-notes/' . self::NONEXISTENT_PUBLIC_ID));
        self::assertSame(404, $missing->getStatusCode());

        $createdBy = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/log-notes?createdBy=' . DevelopmentUserManagementCatalog::USER_PUBLIC_ID,
        ));
        $createdByData = $this->listPayload(TestAppFactory::json($createdBy));
        self::assertGreaterThanOrEqual(1, $createdByData['pagination']['totalItems']);

        $from = $app->handle(TestAppFactory::request('GET', '/api/v1/log-notes?from=2099-01-01'));
        $fromData = $this->listPayload(TestAppFactory::json($from));
        self::assertSame(0, $fromData['pagination']['totalItems']);
    }

    public function test_ai_summarise_does_not_persist_and_create_can_flag_ai(): void
    {
        $app = TestAppFactory::create();
        $customerPublicId = $this->createCustomer($app, 'AI Notes Co', 'AI-LN');

        $summarise = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes/summarise', [
            'text' => 'Called Jane about BV 4C 2.5. She wants a quote next week. Follow up Friday.',
        ]));
        $summarisePayload = TestAppFactory::json($summarise);
        self::assertSame(200, $summarise->getStatusCode(), (string) $summarise->getBody());
        /** @var array<string, mixed> $summarised */
        $summarised = $summarisePayload['data'];
        self::assertIsString($summarised['summarisedBody']);
        self::assertStringContainsString('Summary:', (string) $summarised['summarisedBody']);

        $listBefore = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/log-notes?customerId=' . $customerPublicId,
        ));
        $beforeData = $this->listPayload(TestAppFactory::json($listBefore));
        self::assertSame(0, $beforeData['pagination']['totalItems']);

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => $customerPublicId,
            'interactionType' => 'Phone Call',
            'category' => 'standard',
            'body' => $summarised['summarisedBody'],
            'isAiGenerated' => true,
        ]));
        $createdPayload = TestAppFactory::json($create);
        self::assertSame(201, $create->getStatusCode());
        /** @var array<string, mixed> $created */
        $created = $createdPayload['data'];
        self::assertTrue($created['isAiGenerated']);
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
    private function createCustomer(
        App $app,
        string $customerName,
        ?string $customerCode = null,
        ?string $countryCode = null,
        ?string $territory = null,
    ): string {
        $body = ['customerName' => $customerName];
        if ($customerCode !== null) {
            $body['customerCode'] = $customerCode;
        }
        if ($countryCode !== null) {
            $body['countryCode'] = $countryCode;
        }
        if ($territory !== null) {
            $body['territory'] = $territory;
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
    private function createContact(App $app, string $customerPublicId, string $displayName, ?string $position = null): string
    {
        $body = ['displayName' => $displayName];
        if ($position !== null) {
            $body['position'] = $position;
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
    private function createNote(
        App $app,
        string $customerPublicId,
        string $body,
        string $interactionType,
        string $category,
    ): string {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/log-notes', [
            'customerPublicId' => $customerPublicId,
            'interactionType' => $interactionType,
            'category' => $category,
            'body' => $body,
        ]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = TestAppFactory::json($response);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsString($data['publicId']);

        return $data['publicId'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{items: list<array<mixed>>, pagination: array{totalItems: int, totalPages: int, page: int, pageSize: int}}
     */
    private function listPayload(array $payload): array
    {
        self::assertIsArray($payload['data']);
        $data = $payload['data'];
        self::assertIsArray($data['items']);
        self::assertIsArray($data['pagination']);
        $pagination = $data['pagination'];
        self::assertIsInt($pagination['totalItems'] ?? null);
        self::assertIsInt($pagination['totalPages'] ?? null);
        self::assertIsInt($pagination['page'] ?? null);
        self::assertIsInt($pagination['pageSize'] ?? null);

        $items = [];
        foreach ($data['items'] as $item) {
            self::assertIsArray($item);
            $items[] = $item;
        }

        return [
            'items' => $items,
            'pagination' => [
                'totalItems' => $pagination['totalItems'],
                'totalPages' => $pagination['totalPages'],
                'page' => $pagination['page'],
                'pageSize' => $pagination['pageSize'],
            ],
        ];
    }

    private function itemPublicId(mixed $item): string
    {
        self::assertIsArray($item);
        self::assertIsString($item['publicId'] ?? null);

        return $item['publicId'];
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
     * @param App<ContainerInterface> $app
     */
    private function externalSync(App $app): DisabledCustomerNoteExternalSync
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $sync = $container->get(CustomerNoteExternalSync::class);
        self::assertInstanceOf(DisabledCustomerNoteExternalSync::class, $sync);

        return $sync;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function audit(App $app): InMemoryLogNoteAuditRecorder
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $audit = $container->get(LogNoteAuditRecorder::class);
        self::assertInstanceOf(InMemoryLogNoteAuditRecorder::class, $audit);

        return $audit;
    }
}
