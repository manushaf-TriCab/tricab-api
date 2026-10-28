<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class CustomerContactReportApprovalEndpointsTest extends TestCase
{
    /** @var list<string> */
    private const STAFF = [
        'ACCOUNT.LOGIN',
        Module::PERMISSION_ACCESS,
        Module::PERMISSION_READ,
        Module::PERMISSION_CREATE,
        Module::PERMISSION_REMEDY,
        Module::PERMISSION_APPROVE,
    ];

    public function test_remedy_and_approve_are_not_employee_grants(): void
    {
        self::assertNotContains(Module::PERMISSION_REMEDY, Module::EMPLOYEE_PERMISSIONS);
        self::assertNotContains(Module::PERMISSION_APPROVE, Module::EMPLOYEE_PERMISSIONS);
        self::assertNotContains(Module::PERMISSION_REMEDY, DevelopmentUserManagementCatalog::managementPermissionCodes());
        self::assertNotContains(Module::PERMISSION_APPROVE, DevelopmentUserManagementCatalog::managementPermissionCodes());
    }

    public function test_credit_request_goes_to_the_assignee_and_rejects_client_routing_and_rework(): void
    {
        $app = $this->app();
        $this->register($app, 'alex@tricab.com', 'Alex Assignee');
        $created = $this->created($app);
        $publicId = $this->text($created, 'publicId');

        $denied = $app->handle($this->json('POST', $this->requestPath($publicId, 'credit'), [
            'approver' => 'alex@tricab.com',
            'creditTotal' => '10.00',
            'rowVersion' => $this->text($created, 'rowVersion'),
        ]));
        self::assertSame(422, $denied->getStatusCode());
        self::assertSame('CLIENT_FIELD_REJECTED', $this->errorCode($denied));

        $this->replacePermissions($app, array_values(array_diff(self::STAFF, [Module::PERMISSION_REMEDY])));
        $forbidden = $app->handle($this->json('POST', $this->requestPath($publicId, 'credit'), [
            'creditTotal' => '10.00',
            'rowVersion' => $this->text($created, 'rowVersion'),
        ]));
        self::assertSame(403, $forbidden->getStatusCode());

        $this->replacePermissions($app, self::STAFF);
        self::assertSame(422, $app->handle($this->json('POST', $this->requestPath($publicId, 'credit'), [
            'approverEmail' => 'forged@tricab.com',
            'rowVersion' => $this->text($created, 'rowVersion'),
        ]))->getStatusCode());

        // rework is retired for requests.
        self::assertSame(422, $app->handle($this->json('POST', $this->requestPath($publicId, 'rework'), [
            'rowVersion' => $this->text($created, 'rowVersion'),
        ]))->getStatusCode());

        // An unassigned (submitted) report has no credit owner yet.
        $unassigned = $app->handle($this->json('POST', $this->requestPath($publicId, 'credit'), [
            'creditTotal' => '1000.01',
            'rowVersion' => $this->text($created, 'rowVersion'),
        ]));
        self::assertSame(503, $unassigned->getStatusCode());
        self::assertSame('CUSTOMER_CONTACT_APPROVAL_CONFIGURATION_ERROR', $this->errorCode($unassigned));

        $assigned = $this->assign($app, $created, 'alex@tricab.com');
        $requested = $this->record($this->data($app->handle($this->json('POST', $this->requestPath($publicId, 'credit'), [
            'creditTotal' => '5000.00',
            'rowVersion' => $this->text($assigned, 'rowVersion'),
        ]))));
        self::assertSame('assigned', $requested['status'] ?? null);
        $approval = $this->approval($requested, 'credit');
        self::assertSame('pending', $approval['status'] ?? null);
        self::assertSame('alex@tricab.com', $approval['approver'] ?? null);
        self::assertSame('Alex Assignee', $approval['approverName'] ?? null);
        self::assertSame('thilanh@tricab.com', $approval['requestedBy'] ?? null);
        self::assertIsString($approval['requestedAtUtc'] ?? null);
        self::assertNotSame('', $approval['requestedAtUtc']);

        $listed = $this->record($this->data($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts'))));
        $items = $listed['items'] ?? null;
        self::assertIsArray($items);
        $first = $this->record($items[0] ?? null);
        $summary = $this->record($this->recordList($first['approvals'] ?? null)[0] ?? null);
        self::assertSame(['kind' => 'credit', 'status' => 'pending'], $summary);

        $wrong = $app->handle($this->json('POST', $this->decisionPath($publicId, 'credit'), [
            'decision' => 'approved',
            'rowVersion' => $this->text($approval, 'rowVersion'),
        ]));
        self::assertSame(403, $wrong->getStatusCode());
        self::assertSame('CUSTOMER_CONTACT_APPROVAL_NOT_APPROVER', $this->errorCode($wrong));
    }

    public function test_the_assignee_can_decide_a_credit_and_a_legacy_rework_row_still_decides(): void
    {
        $app = $this->app(['CUSTOMER_CONTACT_ACCOUNTS_LOGIN' => 'accounts@tricab.com']);
        $alex = ['subject' => 'development-cc-alex', 'email' => 'alex@tricab.com'];
        $this->registerActor($app, 'alex@tricab.com', 'Alex Assignee', $alex['subject'], self::STAFF);
        $this->register($app, 'accounts@tricab.com', 'Accounts');
        $created = $this->created($app);
        $publicId = $this->text($created, 'publicId');

        // The raiser cannot be their own credit approver.
        $own = $this->created($app);
        $ownAssigned = $this->assign($app, $own, 'thilanh@tricab.com');
        $self = $app->handle($this->json('POST', $this->requestPath($this->text($own, 'publicId'), 'credit'), [
            'creditTotal' => '10.00',
            'rowVersion' => $this->text($ownAssigned, 'rowVersion'),
        ]));
        self::assertSame(403, $self->getStatusCode());
        self::assertSame('CUSTOMER_CONTACT_APPROVAL_SELF', $this->errorCode($self));

        $assigned = $this->assign($app, $created, 'alex@tricab.com');
        $requested = $this->record($this->data($app->handle($this->json('POST', $this->requestPath($publicId, 'credit'), [
            'creditTotal' => '80.00',
            'rowVersion' => $this->text($assigned, 'rowVersion'),
        ]))));
        $approval = $this->approval($requested, 'credit');

        $declined = $app->handle($this->json('POST', $this->decisionPath($publicId, 'credit'), [
            'decision' => 'declined',
            'rowVersion' => $this->text($approval, 'rowVersion'),
        ], $alex));
        self::assertSame(422, $declined->getStatusCode());
        $approved = $this->record($this->data($app->handle($this->json('POST', $this->decisionPath($publicId, 'credit'), [
            'decision' => 'approved',
            'rowVersion' => $this->text($approval, 'rowVersion'),
        ], $alex))));
        $decided = $this->approval($approved, 'credit');
        self::assertSame('approved', $decided['status'] ?? null);
        self::assertSame('assigned', $approved['status'] ?? null);
        self::assertNotNull($decided['decidedAtUtc'] ?? null);
        self::assertNotSame($approval['rowVersion'] ?? null, $decided['rowVersion'] ?? null);
        self::assertSame('pending', $this->approval($approved, 'settlement')['status'] ?? null);

        $again = $app->handle($this->json('POST', $this->decisionPath($publicId, 'credit'), [
            'decision' => 'declined',
            'note' => 'Too late.',
            'rowVersion' => $this->text($decided, 'rowVersion'),
        ], $alex));
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('CUSTOMER_CONTACT_APPROVAL_NOT_PENDING', $this->errorCode($again));

        $stale = $app->handle($this->json('POST', $this->decisionPath($publicId, 'credit'), [
            'decision' => 'declined',
            'note' => 'Stale.',
            'rowVersion' => $this->text($approval, 'rowVersion'),
        ], $alex));
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('CUSTOMER_CONTACT_APPROVAL_CONFLICT', $this->errorCode($stale));

        $repo = $this->container($app)->get(CustomerContactReportRepository::class);
        self::assertInstanceOf(InMemoryCustomerContactReportRepository::class, $repo);
        $source = $this->stored($repo, $publicId);

        // Legacy rework rows (requested before the retirement) stay readable and decidable by their approver.
        $hidden = $repo->seedCopy($source, 90, 'other@tricab.com', 'assigned', '2609-009M-SLS', 'branch', 'SLS', 'alex@tricab.com');
        $legacy = $this->seedRework($app, $repo, $hidden, false);
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_READ]);
        $read = $this->record($this->data($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $hidden->publicId))));
        self::assertSame('rework', $this->approval($read, 'rework')['kind'] ?? null);
        $this->replacePermissions($app, self::STAFF);
        $decidedLegacy = $this->record($this->data($app->handle($this->json('POST', $this->decisionPath($hidden->publicId, 'rework'), [
            'decision' => 'approved',
            'rowVersion' => $legacy,
        ]))));
        self::assertSame('approved', $this->approval($decidedLegacy, 'rework')['status'] ?? null);

        $stranger = $repo->seedCopy($hidden, 91, 'stranger@tricab.com');
        self::assertSame(404, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $stranger->publicId))->getStatusCode());
        $this->replacePermissions($app, [
            'ACCOUNT.LOGIN',
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_READ,
            Module::PERMISSION_READ_ALL,
            Module::PERMISSION_REMEDY,
            Module::PERMISSION_APPROVE,
        ]);
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $stranger->publicId))->getStatusCode());

        $someoneElses = $repo->seedCopy($source, 92, 'owner@tricab.com', 'assigned', '2609-010M-SLS', 'branch', 'SLS', 'alex@tricab.com');
        $theirs = $this->seedRework($app, $repo, $someoneElses, true);
        $notMine = $app->handle($this->json('POST', $this->decisionPath($someoneElses->publicId, 'rework'), [
            'decision' => 'declined',
            'note' => 'I can see everything.',
            'rowVersion' => $theirs,
        ]));
        self::assertSame(403, $notMine->getStatusCode());
        self::assertSame('CUSTOMER_CONTACT_APPROVAL_NOT_APPROVER', $this->errorCode($notMine));
    }

    /**
     * Seeds an old-style pending rework row ("alex" or "thilanh" as approver, the other as requester).
     * Returns its row version.
     *
     * @param App<ContainerInterface> $app
     */
    private function seedRework(
        App $app,
        InMemoryCustomerContactReportRepository $repo,
        \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails $report,
        bool $alexApproves,
    ): string {
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $alexUser = $users->findByEmail(EmailAddress::fromString('alex@tricab.com'));
        self::assertNotNull($alexUser);
        $alex = [$alexUser->id()->toInt(), 'alex@tricab.com', 'Alex Assignee'];
        $thilan = [1, 'thilanh@tricab.com', 'Thilan Lakshitha'];
        [$approver, $requester] = $alexApproves ? [$alex, $thilan] : [$thilan, $alex];
        $outcome = $repo->requestApproval(new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportApprovalRequest(
            publicId: $report->publicId,
            expectedReportRowVersion: $report->rowVersion,
            kind: 'rework',
            approverLogin: $approver[1],
            approverUserId: $approver[0],
            approverName: $approver[2],
            approverEmail: $approver[1],
            requestedBy: $requester[1],
            requestedByUserId: $requester[0],
            requestedAtUtc: new \DateTimeImmutable('2026-09-30 01:00:00', new \DateTimeZone('UTC')),
            writeCreditFigures: false,
            valueExGst: null,
            restockingAmount: null,
            subTotal: null,
            gst: null,
            creditTotal: null,
        ));

        return $outcome->approval->rowVersion;
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

        return $this->data($response);
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
    /**
     * @param list<string> $permissions
     * @param App<ContainerInterface> $app
     */
    private function registerActor(App $app, string $email, string $name, string $subject, array $permissions): void
    {
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->register(
            EmailAddress::fromString($email),
            $name,
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            array_map(
                static fn (string $code): PermissionCode => PermissionCode::fromString($code),
                $permissions,
            ),
            new ExternalIdentity(
                ExternalProvider::fromString(ExternalProvider::MICROSOFT_ENTRA),
                ExternalSubjectId::fromString($subject),
                ExternalTenantId::fromString('development-tenant'),
            ),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function register(App $app, string $email, string $name): void
    {
        $users = $this->container($app)->get(UserProvisioningRepository::class);
        self::assertInstanceOf(UserProvisioningRepository::class, $users);
        $users->register(
            EmailAddress::fromString($email),
            $name,
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            [PermissionCode::fromString('ACCOUNT.LOGIN')],
            EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('seed-' . $email)),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed>
     */
    private function created(App $app): array
    {
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

    private function requestPath(string $publicId, string $kind): string
    {
        return '/api/v1/customer-contacts/' . $publicId . '/approvals/' . $kind . '/request';
    }

    private function decisionPath(string $publicId, string $kind): string
    {
        return '/api/v1/customer-contacts/' . $publicId . '/approvals/' . $kind . '/decision';
    }

    /**
     * @param array<string, mixed> $body
     * @param array{email?:string,subject?:string}|null $actor
     */
    private function json(string $method, string $path, array $body, ?array $actor = null): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        if (isset($actor['subject'])) {
            $request = $request->withHeader('X-Dev-Entra-Subject', $actor['subject']);
        }
        if (isset($actor['email'])) {
            $request = $request->withHeader('X-Dev-Entra-Email', $actor['email']);
        }
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(\Psr\Http\Message\ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->record(TestAppFactory::json($response)['data'] ?? null);
    }

    private function errorCode(\Psr\Http\Message\ResponseInterface $response): string
    {
        $error = $this->record(TestAppFactory::json($response)['error'] ?? null);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function approval(array $payload, string $kind): array
    {
        foreach ($this->recordList($payload['approvals'] ?? null) as $approval) {
            if (($approval['kind'] ?? null) === $kind) {
                return $approval;
            }
        }
        self::fail('Missing ' . $kind . ' approval.');
    }

    private function stored(InMemoryCustomerContactReportRepository $reports, string $publicId): \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails
    {
        $found = $reports->findVisible(
            \TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportPublicId::fromString($publicId),
            new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        );
        self::assertNotNull($found);

        return $found;
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
     * @return list<array<string, mixed>>
     */
    private function recordList(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected a list.');
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = $this->record($item);
        }

        return $items;
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
