<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class AdminUserManagementEndpointsTest extends TestCase
{
    private const THILAN_PUBLIC_ID = DevelopmentUserManagementCatalog::USER_PUBLIC_ID;

    // Well-formed v4 UUID (passes UserPublicId's format validation) that is
    // never seeded, so lookups against it exercise the 404 USER_NOT_FOUND path.
    private const NONEXISTENT_PUBLIC_ID = '11111111-1111-4111-8111-111111111111';

    public function test_admin_routes_require_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/users'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($payload['success']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('UNAUTHENTICATED', $error['code']);
    }

    public function test_admin_routes_require_permission(): void
    {
        $app = TestAppFactory::create();
        $this->stripAdminPermissions($app);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/users'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($payload['success']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('INSUFFICIENT_PERMISSION', $error['code']);
    }

    public function test_list_users_returns_items_pagination_and_counts(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/users'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['items']);
        self::assertIsArray($data['pagination']);
        self::assertIsArray($data['counts']);

        /** @var list<array<string, mixed>> $items */
        $items = $data['items'];
        $thilan = self::findByPublicId($items, self::THILAN_PUBLIC_ID);
        self::assertNotNull($thilan);
        self::assertArrayNotHasKey('userId', $thilan);
        /** @var list<string> $roles */
        $roles = $thilan['roles'];
        self::assertContains('ADMINISTRATOR', $roles);
    }

    public function test_get_user_returns_details_with_effective_permissions(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/users/' . self::THILAN_PUBLIC_ID));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame(self::THILAN_PUBLIC_ID, $data['publicId']);
        self::assertArrayNotHasKey('userId', $data);
        self::assertIsArray($data['effectivePermissions']);
        /** @var list<array<string, mixed>> $effectivePermissions */
        $effectivePermissions = $data['effectivePermissions'];
        $permissionCodes = array_map(
            static fn (array $grant): mixed => $grant['permissionCode'],
            $effectivePermissions,
        );
        self::assertContains('USER.READ', $permissionCodes);
        self::assertIsArray($data['identityProviders']);
        self::assertIsArray($data['recentAuditEvents']);
    }

    public function test_get_user_not_found(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/users/' . self::NONEXISTENT_PUBLIC_ID),
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(404, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('USER_NOT_FOUND', $error['code']);
    }

    public function test_create_user_success(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/users', [
            'emailAddress' => 'new.user@tricab.com',
            'displayName' => 'New User',
            'roles' => ['EMPLOYEE'],
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(201, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('new.user@tricab.com', $data['emailAddress']);
        self::assertSame('active', $data['status']);
        self::assertArrayNotHasKey('userId', $data);
    }

    public function test_create_user_rejects_duplicate_email(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/users', [
            'emailAddress' => DevelopmentUserManagementCatalog::USER_EMAIL,
            'displayName' => 'Duplicate',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(409, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('USER_EMAIL_CONFLICT', $error['code']);
    }

    public function test_create_user_validates_required_fields(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/users', [
            'displayName' => 'Missing Email',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('VALIDATION_FAILED', $error['code']);
    }

    public function test_update_user_success_and_not_found(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createUser($app, 'update.me@tricab.com', 'Original Name');

        $response = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/users/' . $publicId, [
            'displayName' => 'Updated Name',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('Updated Name', $data['displayName']);

        $notFound = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/users/' . self::NONEXISTENT_PUBLIC_ID,
            ['displayName' => 'Nobody'],
        ));
        $notFoundPayload = TestAppFactory::json($notFound);
        self::assertSame(404, $notFound->getStatusCode());
        /** @var array<string, mixed> $notFoundError */
        $notFoundError = $notFoundPayload['error'];
        self::assertSame('USER_NOT_FOUND', $notFoundError['code']);
    }

    public function test_change_user_status_success(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createUser($app, 'status.change@tricab.com', 'Status Change');

        $response = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/users/' . $publicId . '/status',
            ['status' => UserStatus::DISABLED],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame(UserStatus::DISABLED, $data['status']);
    }

    public function test_change_own_status_triggers_self_lockout_protection(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/admin/users/' . self::THILAN_PUBLIC_ID . '/status',
            ['status' => UserStatus::DISABLED],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(409, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('SELF_LOCKOUT_PROTECTION', $error['code']);
    }

    public function test_remove_own_administrator_role_triggers_self_lockout_protection(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle(
            TestAppFactory::request('DELETE', '/api/v1/admin/users/' . self::THILAN_PUBLIC_ID . '/roles/ADMINISTRATOR'),
        );
        $payload = TestAppFactory::json($response);

        self::assertSame(409, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('SELF_LOCKOUT_PROTECTION', $error['code']);
    }

    public function test_last_admin_protection_when_different_actor_targets_sole_administrator(): void
    {
        $app = TestAppFactory::create();
        $this->registerSecondaryActor($app, 'ops-actor', ['ACCOUNT.LOGIN', 'USER.STATUS.UPDATE']);

        $request = TestAppFactory::request(
            'PATCH',
            '/api/v1/admin/users/' . self::THILAN_PUBLIC_ID . '/status',
        )
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_TENANT, 'development-tenant')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, 'ops-actor')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode(['status' => UserStatus::DISABLED], JSON_THROW_ON_ERROR));

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(409, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('LAST_ADMIN_PROTECTION', $error['code']);
    }

    public function test_assign_and_remove_user_role(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createUser($app, 'role.assignment@tricab.com', 'Role Assignment');

        $assignResponse = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/users/' . $publicId . '/roles',
            ['roleCode' => 'EMPLOYEE'],
        ));
        $assignPayload = TestAppFactory::json($assignResponse);
        self::assertSame(200, $assignResponse->getStatusCode());
        /** @var array<string, mixed> $assignedData */
        $assignedData = $assignPayload['data'];
        /** @var list<array<string, mixed>> $assignedRoles */
        $assignedRoles = $assignedData['roles'];
        $assignedRoleCodes = array_map(
            static fn (array $role): mixed => $role['roleCode'],
            $assignedRoles,
        );
        self::assertContains('EMPLOYEE', $assignedRoleCodes);

        $removeResponse = $app->handle(
            TestAppFactory::request('DELETE', '/api/v1/admin/users/' . $publicId . '/roles/EMPLOYEE'),
        );
        $removePayload = TestAppFactory::json($removeResponse);
        self::assertSame(200, $removeResponse->getStatusCode());
        /** @var array<string, mixed> $removedData */
        $removedData = $removePayload['data'];
        /** @var list<array<string, mixed>> $removedRoles */
        $removedRoles = $removedData['roles'];
        $activeRoleCodes = array_map(
            static fn (array $role): mixed => $role['roleCode'],
            array_filter($removedRoles, static fn (array $role): bool => $role['isActive'] === true),
        );
        self::assertNotContains('EMPLOYEE', $activeRoleCodes);
    }

    public function test_assigning_inactive_role_returns_validation_error(): void
    {
        $app = TestAppFactory::create();
        $publicId = $this->createUser($app, 'inactive.role@tricab.com', 'Inactive Role Target');

        $createRole = $app->handle($this->jsonRequest('POST', '/api/v1/admin/roles', [
            'roleCode' => 'DORMANT_ROLE',
            'roleName' => 'Dormant Role',
            'isActive' => false,
        ]));
        self::assertSame(201, $createRole->getStatusCode());

        $response = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/users/' . $publicId . '/roles',
            ['roleCode' => 'DORMANT_ROLE'],
        ));
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('VALIDATION_FAILED', $error['code']);
    }

    public function test_role_crud_lifecycle(): void
    {
        $app = TestAppFactory::create();

        $list = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/roles'));
        self::assertSame(200, $list->getStatusCode());

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/roles', [
            'roleCode' => 'SUPPORT_AGENT',
            'roleName' => 'Support Agent',
            'description' => 'Handles support tickets',
        ]));
        $createPayload = TestAppFactory::json($create);
        self::assertSame(201, $create->getStatusCode());
        /** @var array<string, mixed> $createdRole */
        $createdRole = $createPayload['data'];
        self::assertSame('SUPPORT_AGENT', $createdRole['roleCode']);

        $get = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/roles/SUPPORT_AGENT'));
        self::assertSame(200, $get->getStatusCode());

        $update = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/roles/SUPPORT_AGENT', [
            'roleName' => 'Senior Support Agent',
        ]));
        $updatePayload = TestAppFactory::json($update);
        self::assertSame(200, $update->getStatusCode());
        /** @var array<string, mixed> $updatedRole */
        $updatedRole = $updatePayload['data'];
        self::assertSame('Senior Support Agent', $updatedRole['roleName']);

        $assignPermission = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/roles/SUPPORT_AGENT/permissions',
            ['permissionCode' => 'USER.READ'],
        ));
        $assignPermissionPayload = TestAppFactory::json($assignPermission);
        self::assertSame(200, $assignPermission->getStatusCode());
        /** @var array<string, mixed> $roleWithPermission */
        $roleWithPermission = $assignPermissionPayload['data'];
        /** @var list<array<string, mixed>> $rolePermissions */
        $rolePermissions = $roleWithPermission['permissions'];
        $assignedPermissionCodes = array_map(
            static fn (array $permission): mixed => $permission['permissionCode'],
            $rolePermissions,
        );
        self::assertContains('USER.READ', $assignedPermissionCodes);

        $removePermission = $app->handle(
            TestAppFactory::request('DELETE', '/api/v1/admin/roles/SUPPORT_AGENT/permissions/USER.READ'),
        );
        self::assertSame(200, $removePermission->getStatusCode());

        $deactivate = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/roles/SUPPORT_AGENT/status', [
            'isActive' => false,
        ]));
        $deactivatePayload = TestAppFactory::json($deactivate);
        self::assertSame(200, $deactivate->getStatusCode());
        /** @var array<string, mixed> $deactivatedRole */
        $deactivatedRole = $deactivatePayload['data'];
        self::assertFalse($deactivatedRole['isActive']);
    }

    public function test_create_role_rejects_duplicate_code(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/roles', [
            'roleCode' => 'EMPLOYEE',
            'roleName' => 'Employee Duplicate',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(409, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('ROLE_CODE_CONFLICT', $error['code']);
    }

    public function test_deactivating_system_role_is_protected(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/roles/EMPLOYEE/status', [
            'isActive' => false,
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(409, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('SYSTEM_ROLE_PROTECTED', $error['code']);
    }

    public function test_permission_list_create_and_update(): void
    {
        $app = TestAppFactory::create();

        $list = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/permissions'));
        $listPayload = TestAppFactory::json($list);
        self::assertSame(200, $list->getStatusCode());
        /** @var array<string, mixed> $listData */
        $listData = $listPayload['data'];
        self::assertIsArray($listData['items']);
        self::assertNotEmpty($listData['items']);

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/permissions', [
            'permissionCode' => 'REPORTS.EXPORT',
            'permissionName' => 'Export Reports',
            'moduleCode' => 'REPORTS',
        ]));
        $createPayload = TestAppFactory::json($create);
        self::assertSame(201, $create->getStatusCode());
        /** @var array<string, mixed> $createdPermission */
        $createdPermission = $createPayload['data'];
        self::assertSame('REPORTS.EXPORT', $createdPermission['permissionCode']);

        $update = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/permissions/REPORTS.EXPORT', [
            'permissionName' => 'Export All Reports',
        ]));
        $updatePayload = TestAppFactory::json($update);
        self::assertSame(200, $update->getStatusCode());
        /** @var array<string, mixed> $updatedPermission */
        $updatedPermission = $updatePayload['data'];
        self::assertSame('Export All Reports', $updatedPermission['permissionName']);
    }

    public function test_create_permission_rejects_duplicate_code(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/permissions', [
            'permissionCode' => 'USER.READ',
            'permissionName' => 'Duplicate',
            'moduleCode' => 'USER',
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(409, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('PERMISSION_CODE_CONFLICT', $error['code']);
    }

    public function test_deactivating_system_permission_in_use_is_protected(): void
    {
        $app = TestAppFactory::create();

        $response = $app->handle($this->jsonRequest('PATCH', '/api/v1/admin/permissions/ACCOUNT.LOGIN', [
            'permissionName' => 'Account Login',
            'isActive' => false,
        ]));
        $payload = TestAppFactory::json($response);

        self::assertSame(409, $response->getStatusCode());
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('SYSTEM_PERMISSION_PROTECTED', $error['code']);
    }

    public function test_audit_log_records_mutations(): void
    {
        $app = TestAppFactory::create();
        $this->createUser($app, 'audit.trail@tricab.com', 'Audit Trail');

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/user-management/audit'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertIsArray($data['items']);
        self::assertNotEmpty($data['items']);
        self::assertIsArray($data['pagination']);
    }

    public function test_responses_do_not_leak_sensitive_fields(): void
    {
        $app = TestAppFactory::create();

        $listResponse = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/users'));
        $userResponse = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/users/' . self::THILAN_PUBLIC_ID),
        );

        foreach ([$listResponse, $userResponse] as $response) {
            $raw = strtolower((string) $response->getBody());
            self::assertStringNotContainsString('"token"', $raw);
            self::assertStringNotContainsString('"otp"', $raw);
            self::assertStringNotContainsString('"secret"', $raw);
            self::assertStringNotContainsString('"password"', $raw);
        }
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
    private function createUser(App $app, string $emailAddress, string $displayName): string
    {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/users', [
            'emailAddress' => $emailAddress,
            'displayName' => $displayName,
        ]));

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
    private function stripAdminPermissions(App $app): void
    {
        $repository = $this->userRepository($app);
        $repository->replacePermissions(1, ['account.login'], ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissionCodes
     */
    private function registerSecondaryActor(App $app, string $subject, array $permissionCodes): void
    {
        $repository = $this->userRepository($app);

        $repository->register(
            EmailAddress::fromString($subject . '@tricab.com'),
            'Secondary Actor',
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            array_map(
                static fn (string $code): PermissionCode => PermissionCode::fromString($code),
                $permissionCodes,
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
    private function userRepository(App $app): InMemoryApiUserRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        // Fetch by the bound interface (not the concrete class name) so we get
        // the same singleton instance the running app resolves for requests.
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
