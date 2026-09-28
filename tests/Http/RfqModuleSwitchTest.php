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
 * `RFQ_MODULE_ENABLED` lets the RFQ module merge before it is finished.
 *
 * Off is the default and must stay the default: every employee already holds
 * `RFQ.ACCESS` and `RFQ.DRAFT.READ` on tricab-mel, so without the switch a merge
 * to main would put the half-built module in front of all of them. Off, the
 * routes do not exist and `/api/v1/me` says so, which is what the web reads to
 * hide the module from everyone, administrators included.
 */
final class RfqModuleSwitchTest extends TestCase
{
    private const PATHS = ['/api/v1/rfqs/me', '/api/v1/rfqs', '/api/v1/rfqs/11111111-1111-4111-8111-111111111111'];

    public function test_the_module_is_off_unless_switched_on(): void
    {
        $app = $this->app([], ['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]], ['ADMINISTRATOR']);

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            self::assertStringStartsNotWith('/api/v1/rfqs', $route->getPattern());
        }
        foreach (self::PATHS as $path) {
            self::assertSame(404, $app->handle(TestAppFactory::request('GET', $path))->getStatusCode(), $path);
        }
        self::assertSame(['rfq' => false], $this->modules($app));
    }

    public function test_switched_on_the_routes_exist_and_me_says_so(): void
    {
        $app = $this->app(['RFQ_MODULE_ENABLED' => 'true'], ['account.login', ...Module::EMPLOYEE_PERMISSIONS]);

        $patterns = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $patterns[] = $route->getPattern();
        }
        foreach (['/api/v1/rfqs/me', '/api/v1/rfqs', '/api/v1/rfqs/{publicId}'] as $pattern) {
            self::assertContains($pattern, $patterns);
        }
        // An unknown id is a real 404 once the module is on, so only these two are asked.
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/rfqs/me'))->getStatusCode());
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/rfqs'))->getStatusCode());
        self::assertSame(['rfq' => true], $this->modules($app));
    }

    public function test_anything_but_true_leaves_it_off(): void
    {
        foreach (['false', '0', '', 'no'] as $value) {
            self::assertSame(['rfq' => false], $this->modules($this->app(['RFQ_MODULE_ENABLED' => $value], ['account.login'])), $value);
        }
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
