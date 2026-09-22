<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileAdminQuery;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Expense\Contract\ExpenseProfileAdminQuery;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class TriExpenseAdminEndpointsTest extends TestCase
{
    public function test_admin_overview_requires_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/overview'));
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_normal_access_user_rejected_from_admin_apis(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS]);
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com');
        $this->seedProfileQueryUserIds($app, [1]);

        foreach ([
            '/api/v1/tri-expense/admin/overview',
            '/api/v1/tri-expense/admin/users',
            '/api/v1/tri-expense/admin/legacy-approver-mapping',
            '/api/v1/tri-expense/admin/profiles',
        ] as $path) {
            $response = $app->handle(TestAppFactory::request('GET', $path));
            self::assertSame(403, $response->getStatusCode(), $path);
            self::assertSame(
                'TRIEXPENSE_ACCESS_DENIED',
                Payload::obj(TestAppFactory::json($response)['error'])['code'],
                $path,
            );
        }

        $put = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/tri-expense/admin/users/550e8400-e29b-41d4-a716-446655440000/te-roles',
            ['access' => true, 'admin' => true, 'fullTeAdministrator' => true],
        ));
        self::assertSame(403, $put->getStatusCode());
        self::assertSame(
            'TRIEXPENSE_ACCESS_DENIED',
            Payload::obj(TestAppFactory::json($put)['error'])['code'],
        );
    }

    public function test_approver_only_user_rejected_from_admin_apis(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, [
            'ACCOUNT.LOGIN',
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_APPROVE,
        ]);
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com');
        $this->seedProfileQueryUserIds($app, [1]);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/overview'));
        self::assertSame(403, $response->getStatusCode());

        $put = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/tri-expense/admin/users/550e8400-e29b-41d4-a716-446655440000/te-roles',
            ['fullTeAdministrator' => true],
        ));
        self::assertSame(403, $put->getStatusCode());
    }

    public function test_finance_can_list_profiles_but_not_manage_te_roles(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, [
            'ACCOUNT.LOGIN',
            Module::PERMISSION_ACCESS,
            Module::PERMISSION_FINANCE,
        ]);
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com', finance: true);
        $this->seedProfileQueryUserIds($app, [1]);

        $profiles = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/profiles'));
        self::assertSame(200, $profiles->getStatusCode());

        $overview = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/overview'));
        self::assertSame(403, $overview->getStatusCode());

        $users = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/users'));
        self::assertSame(403, $users->getStatusCode());
    }

    public function test_full_te_admin_overview_users_roles_and_dry_check(): void
    {
        $app = $this->appAsTeAdmin();

        $overview = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/overview'));
        self::assertSame(200, $overview->getStatusCode(), (string) $overview->getBody());
        $ov = Payload::obj(TestAppFactory::json($overview)['data']);
        self::assertArrayHasKey('users', $ov);
        self::assertArrayHasKey('missingApprovers', $ov);
        self::assertGreaterThanOrEqual(1, (int) $ov['profiles']);
        self::assertSame(1, (int) $ov['missingApprovers']);

        $targetPublicId = $this->createManagedUser($app, 'te.target@tricab.com', 'TE Target');

        $put = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/tri-expense/admin/users/' . $targetPublicId . '/te-roles',
            [
                'access' => true,
                'approve' => true,
                'finance' => true,
                'admin' => true,
                'audit' => true,
                'fullTeAdministrator' => true,
            ],
        ));
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        $data = Payload::obj(TestAppFactory::json($put)['data']);
        self::assertContains(Module::ROLE_FINANCE, $data['roles']);
        self::assertContains(Module::ROLE_ADMINISTRATOR, $data['roles']);
        $perms = Payload::obj($data['permissions']);
        self::assertTrue($perms['access']);
        self::assertTrue($perms['approve']);
        self::assertTrue($perms['finance']);
        self::assertTrue($perms['admin']);
        self::assertTrue($perms['audit']);
        self::assertTrue($data['fullTeAdministrator']);

        // Downgrade to access-only via TE roles
        $putAccess = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/tri-expense/admin/users/' . $targetPublicId . '/te-roles',
            ['access' => true],
        ));
        self::assertSame(200, $putAccess->getStatusCode(), (string) $putAccess->getBody());
        $accessData = Payload::obj(TestAppFactory::json($putAccess)['data']);
        self::assertContains(Module::ROLE_USER, $accessData['roles']);
        self::assertNotContains(Module::ROLE_ADMINISTRATOR, $accessData['roles']);

        $listed = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/users?search=TE%20Target'));
        self::assertSame(200, $listed->getStatusCode());
        $items = Payload::obj(TestAppFactory::json($listed)['data'])['items'];
        self::assertIsArray($items);
        self::assertNotEmpty($items);

        $dry = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/legacy-approver-mapping'));
        self::assertSame(200, $dry->getStatusCode());
        $dryData = Payload::obj(TestAppFactory::json($dry)['data']);
        self::assertFalse($dryData['available']);
        self::assertSame('dry_run', $dryData['mode']);
    }

    public function test_self_escalation_blocked_for_access_only(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS]);
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com');

        $response = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/tri-expense/admin/users/550e8400-e29b-41d4-a716-446655440000/te-roles',
            ['fullTeAdministrator' => true, 'admin' => true, 'finance' => true],
        ));
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_invalid_approver_rejected(): void
    {
        $app = $this->appAsTeAdmin();
        $response = $app->handle($this->jsonRequest(
            'PATCH',
            '/api/v1/tri-expense/admin/profiles/550e8400-e29b-41d4-a716-446655440000/approver',
            ['managerPublicId' => '00000000-0000-4000-8000-000000000099'],
        ));
        self::assertContains($response->getStatusCode(), [400, 404, 422]);
    }

    public function test_employee_search_finds_non_te_internal_user(): void
    {
        $app = $this->appAsTeAdmin();
        $publicId = $this->createManagedUser($app, 'sarahs@tricab.com', 'Sarah Smith');

        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/tri-expense/admin/employee-search?q=Sarah&purpose=te_user',
        ));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $items = Payload::obj(TestAppFactory::json($response)['data'])['items'];
        self::assertIsArray($items);
        $match = null;
        foreach ($items as $item) {
            if (($item['publicId'] ?? null) === $publicId) {
                $match = $item;
                break;
            }
        }
        self::assertNotNull($match);
        self::assertTrue($match['onboarded']);
        self::assertFalse($match['teConfigured']);
        self::assertTrue($match['eligible']);
    }

    public function test_add_te_user_creates_roles_and_profile(): void
    {
        $app = $this->appAsTeAdmin();
        $publicId = $this->createManagedUser($app, 'new.te@tricab.com', 'New TE Employee');

        $before = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/overview'));
        $beforeUsers = (int) Payload::obj(TestAppFactory::json($before)['data'])['users'];

        $put = $app->handle($this->jsonRequest(
            'PUT',
            '/api/v1/tri-expense/admin/users/' . $publicId . '/te-roles',
            ['access' => true],
        ));
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        $data = Payload::obj(TestAppFactory::json($put)['data']);
        self::assertTrue($data['profileCreated']);
        self::assertTrue($data['hasExpenseProfile']);
        self::assertFalse($data['hasApprover']);
        self::assertContains(Module::ROLE_USER, $data['roles']);

        $after = $app->handle(TestAppFactory::request('GET', '/api/v1/tri-expense/admin/overview'));
        $afterUsers = (int) Payload::obj(TestAppFactory::json($after)['data'])['users'];
        self::assertSame($beforeUsers + 1, $afterUsers);
    }

    public function test_approver_search_includes_pending_internal_users(): void
    {
        $app = $this->appAsTeAdmin();
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/users', [
            'emailAddress' => 'pending.mgr@tricab.com',
            'displayName' => 'Pending Manager',
            'status' => 'pending',
        ]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $publicId = Payload::str(Payload::obj(TestAppFactory::json($response)['data'])['publicId']);

        $search = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/tri-expense/admin/employee-search?q=Pending%20Manager&purpose=approver',
        ));
        self::assertSame(200, $search->getStatusCode(), (string) $search->getBody());
        $items = Payload::obj(TestAppFactory::json($search)['data'])['items'];
        $match = null;
        foreach ($items as $item) {
            if (($item['publicId'] ?? null) === $publicId) {
                $match = $item;
                break;
            }
        }
        self::assertNotNull($match);
        self::assertSame('pending', $match['status']);
        self::assertTrue($match['eligible']);
    }

    public function test_normal_user_cannot_employee_search(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS]);
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com');
        $response = $app->handle(TestAppFactory::request(
            'GET',
            '/api/v1/tri-expense/admin/employee-search?q=thilan&purpose=te_user',
        ));
        self::assertSame(403, $response->getStatusCode());
    }

    /** @return App<ContainerInterface> */
    private function appAsTeAdmin(): App
    {
        $app = TestAppFactory::create();
        // Keep default platform ADMIN permissions so POST /admin/users works in-test,
        // and ensure TE ADMIN + active profile for requireExpenseAdmin.
        $this->seedProfile($app, 1, 'thilan', 'thilanh@tricab.com', admin: true);
        $this->seedProfileQueryUserIds($app, [1]);

        return $app;
    }

    /** @param list<int> $userIds */
    private function seedProfileQueryUserIds(App $app, array $userIds): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $query = $container->get(ExpenseProfileAdminQuery::class);
        self::assertInstanceOf(InMemoryExpenseProfileAdminQuery::class, $query);
        $query->seedUserIds(array_map(
            static fn (int $id): UserId => UserId::fromInt($id),
            $userIds,
        ));
    }

    private function createManagedUser(App $app, string $email, string $displayName): string
    {
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/users', [
            'emailAddress' => $email,
            'displayName' => $displayName,
        ]));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return Payload::str(Payload::obj(TestAppFactory::json($response)['data'])['publicId']);
    }

    private function seedProfile(
        App $app,
        int $userId,
        string $login,
        string $email,
        bool $active = true,
        bool $finance = false,
        bool $admin = false,
        ?int $managerUserId = null,
    ): void {
        $this->profiles($app)->save(new ExpenseProfile(
            UserId::fromInt($userId),
            $login,
            $email,
            $active,
            $finance,
            $admin,
            $managerUserId !== null ? 'mgr' : null,
            $managerUserId !== null ? UserId::fromInt($managerUserId) : null,
        ));
    }

    /**
     * @param list<string> $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $this->users($app)->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
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

    private function users(App $app): InMemoryApiUserRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);

        return $repository;
    }

    private function profiles(App $app): InMemoryExpenseProfileRepository
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $profiles = $container->get(ExpenseProfileRepository::class);
        self::assertInstanceOf(InMemoryExpenseProfileRepository::class, $profiles);

        return $profiles;
    }
}
