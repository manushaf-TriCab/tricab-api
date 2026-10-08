<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Rfq\Module;

/**
 * The RFQ module has no on/off switch: its routes always exist, and who may use
 * them is decided by `RFQ.*` permissions alone. `/api/v1/me` lists only the
 * modules that can still be switched off.
 */
final class RfqModuleSwitchTest extends TestCase
{
    public function test_the_routes_always_exist_and_me_lists_no_rfq_switch(): void
    {
        $app = $this->app([], ['account.login', ...Module::EMPLOYEE_PERMISSIONS]);

        $patterns = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $patterns[] = $route->getPattern();
        }
        foreach (['/api/v1/rfqs/me', '/api/v1/rfqs', '/api/v1/rfqs/{publicId}'] as $pattern) {
            self::assertContains($pattern, $patterns);
        }
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/rfqs/me'))->getStatusCode());
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/rfqs'))->getStatusCode());
        self::assertSame(['customerContacts' => false, 'finance' => false], $this->modules($app));
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $permissions
     * @param list<string> $roles
     * @return App<ContainerInterface>
     */
    private function app(array $env, array $permissions, array $roles = ['EMPLOYEE']): App
    {
        $app = TestAppFactory::create($env);
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, $roles);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return mixed
     */
    private function modules(App $app): mixed
    {
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/me'));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['data'] ?? null);

        return $body['data']['modules'] ?? null;
    }
}
