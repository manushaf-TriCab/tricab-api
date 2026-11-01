<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * Finance is switched off by default: with no `FINANCE_*` setting the route
 * table has no finance route and `/api/v1/me` reports `finance: false`, even for
 * someone holding `FINANCE.ACCESS`. An app switch does nothing without the
 * module switch.
 */
final class FinanceModuleSwitchTest extends TestCase
{
    public function test_off_by_default_there_is_no_finance_route_and_me_says_false(): void
    {
        $app = $this->app([], ['account.login', Module::PERMISSION_ACCESS]);

        self::assertSame([], $this->financePatterns($app));
        self::assertSame(404, $app->handle(TestAppFactory::request('GET', '/api/v1/finance/me'))->getStatusCode());
        self::assertFalse($this->modules($app)['finance'] ?? null);
    }

    public function test_an_app_switch_alone_changes_nothing(): void
    {
        $app = $this->app(
            [
                'FINANCE_TASK_ENABLED' => 'true',
                'FINANCE_CREDIT_ENABLED' => 'true',
                'FINANCE_COLLECT_ENABLED' => 'true',
                'FINANCE_AR_ENABLED' => 'true',
                'FINANCE_AP2_ENABLED' => 'true',
            ],
            ['account.login', Module::PERMISSION_ACCESS],
        );

        self::assertSame([], $this->financePatterns($app));
        self::assertFalse($this->modules($app)['finance'] ?? null);
    }

    public function test_on_the_route_exists_and_me_says_true_only_with_the_permission(): void
    {
        $env = ['FINANCE_MODULE_ENABLED' => 'true'];

        $holder = $this->app($env, ['account.login', Module::PERMISSION_ACCESS]);
        self::assertContains('/api/v1/finance/me', $this->financePatterns($holder));
        self::assertSame(200, $holder->handle(TestAppFactory::request('GET', '/api/v1/finance/me'))->getStatusCode());
        self::assertTrue($this->modules($holder)['finance'] ?? null);

        $stranger = $this->app($env, ['account.login']);
        self::assertSame(403, $stranger->handle(TestAppFactory::request('GET', '/api/v1/finance/me'))->getStatusCode());
        self::assertFalse($this->modules($stranger)['finance'] ?? null);
    }

    public function test_the_access_summary_lists_every_app_with_its_switch_and_the_callers_right(): void
    {
        $app = $this->app(
            ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true'],
            ['account.login', Module::PERMISSION_ACCESS, Module::appAccessPermission(Module::APP_TASK), Module::appAccessPermission(Module::APP_AP2)],
        );

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/finance/me'));
        self::assertSame(200, $response->getStatusCode());
        $data = TestAppFactory::json($response)['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['apps']);

        $byKey = [];
        foreach ($data['apps'] as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['key']);
            $byKey[$entry['key']] = $entry;
        }
        self::assertSame(array_keys(Module::APPS), array_keys($byKey));
        self::assertSame(['enabled' => true, 'canAccess' => true], [
            'enabled' => $byKey['TASK']['enabled'],
            'canAccess' => $byKey['TASK']['canAccess'],
        ]);
        // Permitted but switched off: the launcher must not show it.
        self::assertSame(['enabled' => false, 'canAccess' => true], [
            'enabled' => $byKey['AP2']['enabled'],
            'canAccess' => $byKey['AP2']['canAccess'],
        ]);
        // One Tri-AP: the first one's key is gone, and the AP2 app carries its name and path.
        self::assertArrayNotHasKey('AP', $byKey);
        self::assertSame(['Tri-AP', 'ap'], [Module::APPS[Module::APP_AP2]['name'], Module::APPS[Module::APP_AP2]['path']]);
        // Switched on elsewhere but not permitted: also not shown.
        self::assertFalse($byKey['AR']['canAccess']);
    }

    public function test_an_app_switch_has_no_effect_while_the_module_switch_is_off(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'FINANCE_TASK_ENABLED' => 'true',
        ] + self::minimalEnv());

        self::assertFalse($settings->financeAppEnabled('TASK'));

        $on = Settings::fromEnvironment([
            'FINANCE_MODULE_ENABLED' => 'true',
            'FINANCE_TASK_ENABLED' => 'true',
        ] + self::minimalEnv());

        self::assertTrue($on->financeAppEnabled('TASK'));
        self::assertFalse($on->financeAppEnabled('AP2'));
        self::assertFalse($on->financeAppEnabled('AP'), 'the first Tri-AP has no switch any more');
        self::assertFalse($on->financeAppEnabled('UNKNOWN'));
    }

    /**
     * @return array<string, string>
     */
    private static function minimalEnv(): array
    {
        return [
            'APP_ENV' => 'development',
            'APP_NAME' => 'tricab-api',
            'DEV_AUTH_ENABLED' => 'true',
            'USER_REPOSITORY' => 'memory',
            'DB_AUTH_MODE' => 'sql-login',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'GUEST_JWT_TTL_SECONDS' => '86400',
            'GUEST_EMAIL_PROVIDER' => 'development',
            'PASSKEY_FAKE_CEREMONY' => 'true',
        ];
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $permissions
     * @return App<ContainerInterface>
     */
    private function app(array $env, array $permissions): App
    {
        $app = TestAppFactory::create($env);
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<string>
     */
    private function financePatterns(App $app): array
    {
        $patterns = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if (str_starts_with($route->getPattern(), '/api/v1/finance')) {
                $patterns[] = $route->getPattern();
            }
        }

        return $patterns;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<mixed>
     */
    private function modules(App $app): array
    {
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/me'));
        self::assertSame(200, $response->getStatusCode());
        $data = TestAppFactory::json($response)['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['modules'] ?? null);

        return $data['modules'];
    }
}
