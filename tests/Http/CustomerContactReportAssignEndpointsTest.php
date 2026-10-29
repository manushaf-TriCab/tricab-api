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
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class CustomerContactReportAssignEndpointsTest extends TestCase
{
    /** @var list<string> */
    private const STAFF = [
        'ACCOUNT.LOGIN',
        Module::PERMISSION_ACCESS,
        Module::PERMISSION_READ,
        Module::PERMISSION_CREATE,
    ];

    public function test_assignment_requires_access_and_assign_and_employees_do_not_receive_assign(): void
    {
        self::assertNotContains(Module::PERMISSION_ASSIGN, Module::EMPLOYEE_PERMISSIONS);
        self::assertNotContains(Module::PERMISSION_ASSIGN, DevelopmentUserManagementCatalog::managementPermissionCodes());

        $app = $this->app();
        $created = $this->created($app);

        $this->replacePermissions($app, self::STAFF);
        $withoutAssign = $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $this->text($created, 'publicId') . '/assign', $this->assignBody($created)));
        self::assertSame(403, $withoutAssign->getStatusCode());

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ASSIGN, Module::PERMISSION_READ]);
        $withoutAccess = $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $this->text($created, 'publicId') . '/assign', $this->assignBody($created)));
        self::assertSame(403, $withoutAccess->getStatusCode());
    }

    public function test_assignment_validates_route_error_code_assignee_and_row_version(): void
    {
        $app = $this->app();
        $this->replacePermissions($app, [...self::STAFF, Module::PERMISSION_ASSIGN]);
        $this->registerAssignee($app);
        $created = $this->created($app);
        $publicId = $this->text($created, 'publicId');

        $invalidRoute = $this->assignBody($created);
        $invalidRoute['route'] = 'head-office';
        self::assertSame(422, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/assign', $invalidRoute))->getStatusCode());

        $invalidError = $this->assignBody($created);
        $invalidError['errorCode'] = 'NOPE';
        self::assertSame(422, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/assign', $invalidError))->getStatusCode());

        $missingAssignee = $this->assignBody($created);
        unset($missingAssignee['assignedTo']);
        self::assertSame(422, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/assign', $missingAssignee))->getStatusCode());

        $unknown = $this->assignBody($created);
        $unknown['assignedTo'] = 'nobody@tricab.com';
        $unknownResponse = TestAppFactory::json($app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/assign', $unknown)));
        self::assertSame('ASSIGNEE_NOT_FOUND', $this->record($unknownResponse['error'] ?? null)['code'] ?? null);

        $missingVersion = $this->assignBody($created);
        unset($missingVersion['rowVersion']);
        self::assertSame(422, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/assign', $missingVersion))->getStatusCode());

        $malformed = $this->assignBody($created);
        $malformed['rowVersion'] = 'not-a-token';
        self::assertSame(422, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/assign', $malformed))->getStatusCode());

        $injected = $this->assignBody($created);
        $injected['ccNumber'] = '2609-001M-SLS';
        $injected['assignedBy'] = 'ayanj@tricab.com';
        $injectedResponse = TestAppFactory::json($app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/assign', $injected)));
        self::assertSame('CLIENT_FIELD_REJECTED', $this->record($injectedResponse['error'] ?? null)['code'] ?? null);
    }

    public function test_an_authorized_assigner_resolves_the_assignee_and_the_assignee_can_read_it(): void
    {
        $app = $this->app();
        $this->replacePermissions($app, [...self::STAFF, Module::PERMISSION_ASSIGN]);
        $this->registerAssignee($app);
        $created = $this->created($app);

        $response = $app->handle($this->json(
            'POST',
            '/api/v1/customer-contacts/' . $this->text($created, 'publicId') . '/assign',
            $this->assignBody($created),
        ));
        $assigned = $this->record(TestAppFactory::json($response)['data'] ?? null);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('assigned', $assigned['status']);
        self::assertSame('2609-001M-SLS', $assigned['ccNumber']);
        self::assertSame('branch', $assigned['route']);
        self::assertSame('SLS', $assigned['errorCode']);
        self::assertSame('alex@tricab.com', $assigned['assignedTo']);
        self::assertSame('Alex Assignee', $assigned['assignedToName']);
        self::assertSame('alex@tricab.com', $assigned['assignedToEmail']);
        self::assertSame('thilanh@tricab.com', $assigned['assignedBy']);
        self::assertIsString($assigned['assignedAtUtc']);
        self::assertIsString($assigned['rowVersion']);
        self::assertNotSame($created['rowVersion'], $assigned['rowVersion']);
        self::assertArrayNotHasKey('reportId', $assigned);
        self::assertArrayNotHasKey('assignedToUserId', $assigned);

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_READ]);
        $createdId = $this->text($created, 'publicId');
        $asCreator = TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $createdId)));
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $createdId))->getStatusCode());
        self::assertSame($createdId, $this->record($asCreator['data'] ?? null)['publicId'] ?? null);

        $repo = $this->container($app)->get(\TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository::class);
        self::assertInstanceOf(\TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository::class, $repo);
        $hidden = $repo->seedCopy(
            $repo->findVisible(
                \TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId::fromString($this->text($created, 'publicId')),
                new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
            ) ?? $this->fail('assigned report missing'),
            99,
            'other@tricab.com',
        );
        self::assertSame(
            404,
            $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $hidden->publicId))->getStatusCode(),
        );

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_READ, Module::PERMISSION_ASSIGN]);
        $assignedToCaller = TestAppFactory::json($app->handle($this->json(
            'POST',
            '/api/v1/customer-contacts/' . $hidden->publicId . '/assign',
            [
                'route' => 'branch',
                'errorCode' => 'SLS',
                'assignedTo' => 'thilanh@tricab.com',
                'rowVersion' => $hidden->rowVersion,
            ],
        )));
        self::assertSame('assigned', $this->record($assignedToCaller['data'] ?? null)['status'] ?? null);

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_READ]);
        $asAssignee = $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $hidden->publicId));
        self::assertSame(200, $asAssignee->getStatusCode());

        $someoneElse = $repo->seedCopy($hidden, 97, 'stranger@tricab.com', 'assigned', '2609-050M-SLS', 'branch', 'SLS', 'alex@tricab.com');
        self::assertSame(
            404,
            $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $someoneElse->publicId))->getStatusCode(),
        );

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_READ, Module::PERMISSION_READ_ALL]);
        self::assertSame(
            200,
            $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $someoneElse->publicId))->getStatusCode(),
        );
    }

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function app(array $env = []): App
    {
        $app = TestAppFactory::create(['CUSTOMER_CONTACT_MODULE_ENABLED' => 'true', ...$env]);
        $this->replacePermissions($app, self::STAFF);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissions
     */
    private function replacePermissions(App $app, array $permissions): void
    {
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function registerAssignee(App $app): void
    {
        $users = $this->container($app)->get(UserProvisioningRepository::class);
        self::assertInstanceOf(UserProvisioningRepository::class, $users);
        $users->register(
            EmailAddress::fromString('alex@tricab.com'),
            'Alex Assignee',
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            [PermissionCode::fromString('ACCOUNT.LOGIN')],
            EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('alex-seed@tricab.com')),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed>
     */
    private function created(App $app): array
    {
        $request = $this->json('POST', '/api/v1/customer-contacts', [
            'reportDate' => '2026-09-30',
            'location' => 'M',
            'customerCode' => 'CUST1',
            'productCode' => 'CAB-1',
            'orderNo' => '639952',
            'creditRequired' => 'No',
            'description' => 'Cable failed',
        ]);
        $response = $app->handle($request);
        self::assertSame(201, $response->getStatusCode());

        return $this->record(TestAppFactory::json($response)['data'] ?? null);
    }

    /**
     * @param array<string, mixed> $created
     * @return array<string, string>
     */
    private function assignBody(array $created): array
    {
        $rowVersion = $created['rowVersion'] ?? null;
        self::assertIsString($rowVersion);

        return [
            'route' => 'branch',
            'errorCode' => 'SLS',
            'assignedTo' => 'alex@tricab.com',
            'rowVersion' => $rowVersion,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $record
     */
    private function text(array $record, string $key): string
    {
        $value = $record[$key] ?? null;
        if (!is_string($value)) {
            self::fail($key . ' was not a string.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function record(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected a JSON object.');
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail('Expected string keys.');
            }
            $record[$key] = $item;
        }

        return $record;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function container(App $app): ContainerInterface
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
