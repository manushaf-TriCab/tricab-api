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

final class CustomerContactReportWorkflowEndpointsTest extends TestCase
{
    /** @var list<string> */
    private const STAFF = [
        'ACCOUNT.LOGIN',
        Module::PERMISSION_ACCESS,
        Module::PERMISSION_READ,
        Module::PERMISSION_CREATE,
        Module::PERMISSION_COMPLETE,
    ];

    public function test_complete_is_an_employee_grant_and_close_and_reopen_are_not(): void
    {
        self::assertContains(Module::PERMISSION_COMPLETE, Module::EMPLOYEE_PERMISSIONS);
        self::assertNotContains(Module::PERMISSION_CLOSE, Module::EMPLOYEE_PERMISSIONS);
        self::assertNotContains(Module::PERMISSION_REOPEN, Module::EMPLOYEE_PERMISSIONS);
        self::assertNotContains(Module::PERMISSION_ASSIGN, Module::EMPLOYEE_PERMISSIONS);
        self::assertNotContains(Module::PERMISSION_CLOSE, DevelopmentUserManagementCatalog::managementPermissionCodes());
        self::assertNotContains(Module::PERMISSION_REOPEN, DevelopmentUserManagementCatalog::managementPermissionCodes());
    }

    public function test_completion_requires_access_complete_and_current_assignee(): void
    {
        $app = $this->app();
        $this->registerAssignee($app);
        $created = $this->created($app);
        $assigned = $this->assign($app, $created, 'alex@tricab.com');
        $publicId = $this->text($assigned, 'publicId');

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_READ]);
        self::assertSame(403, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/complete', $this->completeBody($assigned)))->getStatusCode());

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_COMPLETE, Module::PERMISSION_READ]);
        self::assertSame(403, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/complete', $this->completeBody($assigned)))->getStatusCode());

        $this->replacePermissions($app, [...self::STAFF, Module::PERMISSION_READ_ALL]);
        $denied = TestAppFactory::json($app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/complete', $this->completeBody($assigned))));
        self::assertSame('CUSTOMER_CONTACT_REPORT_NOT_ASSIGNEE', $this->record($denied['error'] ?? null)['code'] ?? null);

        $mine = $this->assign($app, $this->created($app), 'thilanh@tricab.com');
        $this->replacePermissions($app, self::STAFF);
        $submitted = $this->created($app);
        $tooEarly = TestAppFactory::json($app->handle($this->json(
            'POST',
            '/api/v1/customer-contacts/' . $this->text($submitted, 'publicId') . '/complete',
            $this->completeBody($submitted),
        )));
        self::assertSame('CUSTOMER_CONTACT_REPORT_NOT_COMPLETABLE', $this->record($tooEarly['error'] ?? null)['code'] ?? null);

        $response = $app->handle($this->json(
            'POST',
            '/api/v1/customer-contacts/' . $this->text($mine, 'publicId') . '/complete',
            $this->completeBody($mine),
        ));
        $completed = $this->record(TestAppFactory::json($response)['data'] ?? null);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('completed', $completed['status']);
        self::assertSame($mine['ccNumber'], $completed['ccNumber']);
        self::assertSame('branch', $completed['route']);
        self::assertSame('SLS', $completed['errorCode']);
        self::assertSame('thilanh@tricab.com', $completed['assignedTo']);
        self::assertSame('Replaced the lug', $completed['assigneeActionTaken']);
        self::assertSame('Strand was nicked', $completed['assigneeFindings']);
        self::assertIsString($completed['assigneeCompletedAtUtc']);
        self::assertNotSame($mine['rowVersion'], $completed['rowVersion']);
        self::assertArrayNotHasKey('closedByUserId', $completed);
        self::assertArrayNotHasKey('icarSignatureUrl', $completed);

        $stale = TestAppFactory::json($app->handle($this->json(
            'POST',
            '/api/v1/customer-contacts/' . $this->text($mine, 'publicId') . '/complete',
            ['assigneeActionTaken' => 'again', 'rowVersion' => $this->text($mine, 'rowVersion')],
        )));
        self::assertSame('CUSTOMER_CONTACT_REPORT_CONFLICT', $this->record($stale['error'] ?? null)['code'] ?? null);

        $again = TestAppFactory::json($app->handle($this->json(
            'POST',
            '/api/v1/customer-contacts/' . $this->text($mine, 'publicId') . '/complete',
            $this->completeBody($completed),
        )));
        self::assertSame('CUSTOMER_CONTACT_REPORT_NOT_COMPLETABLE', $this->record($again['error'] ?? null)['code'] ?? null);

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_READ]);
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $this->text($mine, 'publicId')))->getStatusCode());
    }

    public function test_close_and_reopen_are_coordinator_actions_and_keep_the_number(): void
    {
        $app = $this->app();
        $created = $this->created($app);
        $assigned = $this->assign($app, $created, 'thilanh@tricab.com');
        $this->replacePermissions($app, self::STAFF);
        $completedResponse = $app->handle($this->json(
            'POST',
            '/api/v1/customer-contacts/' . $this->text($assigned, 'publicId') . '/complete',
            $this->completeBody($assigned),
        ));
        $completed = $this->record(TestAppFactory::json($completedResponse)['data'] ?? null);
        $publicId = $this->text($completed, 'publicId');

        $this->replacePermissions($app, self::STAFF);
        self::assertSame(403, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/close', $this->versionBody($completed)))->getStatusCode());

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_CLOSE]);
        self::assertSame(403, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/close', $this->versionBody($completed)))->getStatusCode());

        $assignedOnly = $this->assign($app, $this->created($app), 'thilanh@tricab.com');
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_CLOSE]);
        $notClosable = TestAppFactory::json($app->handle($this->json(
            'POST',
            '/api/v1/customer-contacts/' . $this->text($assignedOnly, 'publicId') . '/close',
            $this->versionBody($assignedOnly),
        )));
        self::assertSame('CUSTOMER_CONTACT_REPORT_NOT_CLOSABLE', $this->record($notClosable['error'] ?? null)['code'] ?? null);

        $closedResponse = $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/close', [
            'rowVersion' => $this->text($completed, 'rowVersion'),
            'closedBy' => 'ayanj@tricab.com',
        ]));
        self::assertSame('CLIENT_FIELD_REJECTED', $this->record(TestAppFactory::json($closedResponse)['error'] ?? null)['code'] ?? null);

        $closedResponse = $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/close', $this->versionBody($completed)));
        $closed = $this->record(TestAppFactory::json($closedResponse)['data'] ?? null);
        self::assertSame(200, $closedResponse->getStatusCode());
        self::assertSame('closed', $closed['status']);
        self::assertSame($completed['ccNumber'], $closed['ccNumber']);
        self::assertSame('thilanh@tricab.com', $closed['closedBy']);
        self::assertIsString($closed['closedDate']);
        self::assertIsString($closed['closedAtUtc']);
        self::assertSame('Replaced the lug', $closed['assigneeActionTaken']);
        self::assertNotSame($completed['rowVersion'], $closed['rowVersion']);

        $this->replacePermissions($app, self::STAFF);
        self::assertSame(403, $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/reopen', $this->versionBody($closed)))->getStatusCode());

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_REOPEN]);
        $reopenedResponse = $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $publicId . '/reopen', $this->versionBody($closed)));
        $reopened = $this->record(TestAppFactory::json($reopenedResponse)['data'] ?? null);
        self::assertSame(200, $reopenedResponse->getStatusCode());
        self::assertSame('assigned', $reopened['status']);
        self::assertSame($closed['ccNumber'], $reopened['ccNumber']);
        self::assertSame('branch', $reopened['route']);
        self::assertSame('SLS', $reopened['errorCode']);
        self::assertSame('thilanh@tricab.com', $reopened['assignedTo']);
        self::assertSame('Replaced the lug', $reopened['assigneeActionTaken']);
        self::assertSame('Strand was nicked', $reopened['assigneeFindings']);
        self::assertNull($reopened['assigneeCompletedAtUtc']);
        self::assertSame('thilanh@tricab.com', $reopened['closedBy']);
        self::assertNull($reopened['closedAtUtc']);
        self::assertIsString($reopened['closedDate']);
        self::assertNotSame($closed['rowVersion'], $reopened['rowVersion']);

        $notOpen = TestAppFactory::json($app->handle($this->json(
            'POST',
            '/api/v1/customer-contacts/' . $publicId . '/reopen',
            $this->versionBody($reopened),
        )));
        self::assertSame('CUSTOMER_CONTACT_REPORT_NOT_REOPENABLE', $this->record($notOpen['error'] ?? null)['code'] ?? null);

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_READ]);
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $publicId))->getStatusCode());
    }

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function app(array $env = []): App
    {
        $app = TestAppFactory::create(['CUSTOMER_CONTACT_MODULE_ENABLED' => 'true', ...$env]);
        $this->replacePermissions($app, [...self::STAFF, Module::PERMISSION_ASSIGN]);

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
        $this->replacePermissions($app, [...self::STAFF, Module::PERMISSION_ASSIGN]);
        $response = $app->handle($this->json('POST', '/api/v1/customer-contacts', [
            'reportDate' => '2026-09-30',
            'location' => 'M',
            'customerCode' => 'CUST1',
            'productCode' => 'CAB-1',
            'orderNo' => '639952',
            'creditRequired' => 'No',
            'description' => 'Cable failed',
        ]));
        self::assertSame(201, $response->getStatusCode());

        return $this->record(TestAppFactory::json($response)['data'] ?? null);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $created
     * @return array<string, mixed>
     */
    private function assign(App $app, array $created, string $assignee): array
    {
        $this->replacePermissions($app, [...self::STAFF, Module::PERMISSION_ASSIGN]);
        $response = $app->handle($this->json('POST', '/api/v1/customer-contacts/' . $this->text($created, 'publicId') . '/assign', [
            'route' => 'branch',
            'errorCode' => 'SLS',
            'assignedTo' => $assignee,
            'rowVersion' => $this->text($created, 'rowVersion'),
        ]));
        self::assertSame(200, $response->getStatusCode());

        return $this->record(TestAppFactory::json($response)['data'] ?? null);
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, string>
     */
    private function completeBody(array $report): array
    {
        return [
            'assigneeActionTaken' => 'Replaced the lug',
            'assigneeFindings' => 'Strand was nicked',
            'rowVersion' => $this->text($report, 'rowVersion'),
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, string>
     */
    private function versionBody(array $report): array
    {
        return ['rowVersion' => $this->text($report, 'rowVersion')];
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
