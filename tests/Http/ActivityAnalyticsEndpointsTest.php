<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryUserManagementStore;
use TriCab\Api\Tests\Doubles\FailingUserActivityRepository;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Analytics\Contract\UserActivityRepository;
use TriCab\Core\Analytics\Module;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;

final class ActivityAnalyticsEndpointsTest extends TestCase
{
    private const SIGNAL = [
        'sessionId' => '11111111-1111-4111-8111-000000000001',
        'platform' => 'web',
        'module' => 'products',
        'event' => 'view',
    ];

    private const VIEWS = ['active-users', 'live-users', 'module-activity'];

    public function test_signals_require_authentication(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false', 'USER_ACTIVITY_COLLECTION_ENABLED' => 'true']);
        $response = $app->handle($this->signal(self::SIGNAL));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_collection_is_off_by_default_and_tells_the_client_to_stop(): void
    {
        $app = TestAppFactory::create();
        $this->grant($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ], ['ADMINISTRATOR']);

        $response = $app->handle($this->signal(self::SIGNAL));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['accepted' => false, 'collectionEnabled' => false, 'heartbeatSeconds' => 60],
            Payload::obj(TestAppFactory::json($response)['data']),
        );

        $live = Payload::obj(TestAppFactory::json($app->handle($this->read('live-users')))['data']);
        self::assertSame('not_collecting', $live['status']);
        self::assertSame(1, Payload::obj($live['live'])['total'], 'Heartbeats still count as live while collection is off.');
    }

    public function test_a_recorded_signal_appears_in_every_view_as_counts_only(): void
    {
        $app = TestAppFactory::create(['USER_ACTIVITY_COLLECTION_ENABLED' => 'true']);
        $this->grant($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ], ['ADMINISTRATOR']);

        $signal = $app->handle($this->signal(self::SIGNAL));
        self::assertSame(200, $signal->getStatusCode());
        self::assertTrue(Payload::obj(TestAppFactory::json($signal)['data'])['accepted']);

        $active = $app->handle($this->read('active-users', 'range=today'));
        self::assertSame(200, $active->getStatusCode());
        self::assertSame('private, no-store', $active->getHeaderLine('Cache-Control'));
        $data = Payload::obj(TestAppFactory::json($active)['data']);
        self::assertSame('ok', $data['status']);
        self::assertSame('hour', $data['granularity']);
        self::assertSame('Australia/Melbourne', $data['timezone']);
        self::assertSame(1, Payload::obj(Payload::obj($data['summary'])['today'])['web']);
        self::assertSame(1, Payload::obj($data['summary'])['liveNow']);

        $live = Payload::obj(Payload::obj(TestAppFactory::json($app->handle($this->read('live-users')))['data'])['live']);
        self::assertSame(1, $live['total']);
        self::assertSame(1, $live['staff']);

        $modules = Payload::objList(Payload::obj(TestAppFactory::json($app->handle($this->read('module-activity')))['data'])['modules']);
        self::assertSame('products', $modules[0]['code']);
        self::assertSame('Products & Catalogue', $modules[0]['label']);
        self::assertSame(1, $modules[0]['liveUsers']);

        foreach (self::VIEWS as $view) {
            $body = (string) $app->handle($this->read($view))->getBody();
            self::assertStringNotContainsString('thilanh@tricab.com', $body);
            self::assertStringNotContainsString('publicId', $body);
            self::assertStringNotContainsString('userId', $body);
            self::assertStringNotContainsString('sessionId', $body);
        }
    }

    public function test_malformed_signals_are_rejected(): void
    {
        $app = TestAppFactory::create(['USER_ACTIVITY_COLLECTION_ENABLED' => 'true']);
        $this->grant($app, ['ACCOUNT.LOGIN'], ['EMPLOYEE']);

        $response = $app->handle($this->signal(['platform' => 'desktop'] + self::SIGNAL));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', Payload::obj(TestAppFactory::json($response)['error'])['code']);
    }

    public function test_customers_can_send_signals_but_never_read_analytics(): void
    {
        $app = TestAppFactory::create(['USER_ACTIVITY_COLLECTION_ENABLED' => 'true']);
        $this->grant($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ], ['CUSTOMER_USER']);

        self::assertSame(200, $app->handle($this->signal(self::SIGNAL))->getStatusCode());
        foreach (self::VIEWS as $view) {
            $response = $app->handle($this->read($view));
            self::assertSame(403, $response->getStatusCode(), $view);
        }
    }

    public function test_staff_without_the_analytics_permission_are_forbidden(): void
    {
        $app = TestAppFactory::create(['USER_ACTIVITY_COLLECTION_ENABLED' => 'true']);
        $this->grant($app, ['ACCOUNT.LOGIN', 'USER.READ', 'PRODUCT.READ'], ['EMPLOYEE']);

        foreach (self::VIEWS as $view) {
            $response = $app->handle($this->read($view));
            self::assertSame(403, $response->getStatusCode(), $view);
            self::assertSame('INSUFFICIENT_PERMISSION', Payload::obj(TestAppFactory::json($response)['error'])['code']);
        }
    }

    public function test_invalid_ranges_are_rejected(): void
    {
        $app = TestAppFactory::create(['USER_ACTIVITY_COLLECTION_ENABLED' => 'true']);
        $this->grant($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ], ['ADMINISTRATOR']);

        self::assertSame(422, $app->handle($this->read('active-users', 'range=1y'))->getStatusCode());
        self::assertSame(422, $app->handle($this->read('module-activity', 'sort=name'))->getStatusCode());
    }

    public function test_store_failures_are_reported_as_unavailable_without_details(): void
    {
        $app = TestAppFactory::create(['USER_ACTIVITY_COLLECTION_ENABLED' => 'true']);
        $this->grant($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ], ['ADMINISTRATOR']);
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $container->set(UserActivityRepository::class, new FailingUserActivityRepository());

        foreach (self::VIEWS as $view) {
            $response = $app->handle($this->read($view));
            self::assertSame(503, $response->getStatusCode(), $view);
            self::assertSame('ANALYTICS_UNAVAILABLE', Payload::obj(TestAppFactory::json($response)['error'])['code']);
            self::assertStringNotContainsString('Invalid object name', (string) $response->getBody());
        }

        $signal = $app->handle($this->signal(self::SIGNAL));
        self::assertSame(503, $signal->getStatusCode());
    }

    public function test_the_permission_is_granted_to_administrators_only(): void
    {
        self::assertContains(Module::PERMISSION_READ, DevelopmentUserManagementCatalog::managementPermissionCodes());

        $store = new InMemoryUserManagementStore();
        $store->seedDevelopmentUserManagement();
        foreach (['ADMINISTRATOR' => true, 'EMPLOYEE' => false, 'CUSTOMER_USER' => false, 'GUEST' => false] as $role => $expected) {
            $found = $store->findRoleByCode(RoleCode::fromString($role));
            if ($found === null) {
                self::assertFalse($expected, $role);
                continue;
            }
            $codes = array_map(static fn ($permission): string => strtoupper((string) $permission->permissionCode), $found->permissions);
            self::assertSame($expected, in_array(Module::PERMISSION_READ, $codes, true), $role);
        }
    }

    /**
     * @param array<string, string> $body
     */
    private function signal(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return TestAppFactory::request('POST', '/api/v1/activity/signals')->withParsedBody($body);
    }

    private function read(string $view, string $query = ''): \Psr\Http\Message\ServerRequestInterface
    {
        $request = TestAppFactory::request('GET', '/api/v1/admin/analytics/' . $view);
        if ($query === '') {
            return $request;
        }
        parse_str($query, $params);

        return $request->withQueryParams($params);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    private function grant(App $app, array $permissions, array $roles): void
    {
        /** @var \DI\Container $container */
        $container = $app->getContainer();
        $repo = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repo);
        $repo->replacePermissions(1, $permissions, $roles);
    }
}
