<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Ar\InMemoryArAssigneeDirectory;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * Tri-AR's routes through the real app on the in-memory stores: the switch, the gates, and what
 * each action answers. The Tri-AR routes grow with each task (T5.3 onward). Nothing here reaches a
 * mailbox, a model, Odoo or a database.
 */
final class FinanceArWiringTest extends TestCase
{
    private const ON = ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true'];

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function app(array $env = self::ON): App
    {
        return TestAppFactory::create($env);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissions
     */
    private function grant(App $app, array $permissions): void
    {
        $users = $app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(App $app, string $method, string $path, ?array $body = null): array
    {
        $request = TestAppFactory::request($method, $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function error(array $json): array
    {
        $error = $json['error'];
        self::assertIsArray($error);
        $out = [];
        foreach ($error as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    public function test_with_the_ar_switch_off_nothing_of_tri_ar_is_reachable(): void
    {
        foreach ([['FINANCE_MODULE_ENABLED' => 'true'], ['FINANCE_AR_ENABLED' => 'true'], []] as $env) {
            $app = $this->app($env);
            $this->grant($app, [Module::PERMISSION_AR_ACCESS, ...Module::AR_PERMISSIONS]);
            foreach (['/me', '/assignees', '/settings/options'] as $path) {
                [$status] = $this->call($app, 'GET', '/api/v1/finance/ar' . $path);
                self::assertSame(404, $status, json_encode($env, JSON_THROW_ON_ERROR) . ' ' . $path);
            }
        }
    }

    public function test_me_answers_the_portal_strings_the_mailbox_and_the_link_in_the_platform_envelope(): void
    {
        $app = $this->app(self::ON + ['FINANCE_AR_MAILBOX' => 'AR2@example.test', 'FINANCE_AR_CREDIT_NOTES_URL' => 'https://credit.example.test/']);
        $this->grant($app, [Module::PERMISSION_AR_ACCESS, Module::PERMISSION_AR_RECORDS_READ, Module::PERMISSION_AR_RECORDS_WRITE]);

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/ar/me');

        self::assertSame(200, $status);
        self::assertTrue($json['success']);
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame(['ar.records.read', 'ar.records.write'], $data['permissions']);
        self::assertSame('ar2@example.test', $data['mailbox']);
        self::assertSame('https://credit.example.test', $data['credit_notes_url']);
        self::assertSame('Australia/Melbourne', $data['timezone']);
        self::assertSame('1', $data['id']);
    }

    public function test_the_defaults_are_the_sources_mailbox_and_no_credit_notes_link(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AR_ACCESS]);

        [, $json] = $this->call($app, 'GET', '/api/v1/finance/ar/me');

        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame('ar@tricab.com', $data['mailbox']);
        self::assertSame('', $data['credit_notes_url']);
        self::assertSame([], $data['permissions']);
    }

    public function test_me_is_refused_without_the_app_permission(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AR_RECORDS_READ]);

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/ar/me');

        self::assertSame(403, $status);
        self::assertFalse($json['success']);
    }

    public function test_assignees_list_the_directory(): void
    {
        $app = $this->app();
        $directory = $app->getContainer()->get(ArAssigneeDirectory::class);
        self::assertInstanceOf(InMemoryArAssigneeDirectory::class, $directory);
        $directory->add(9, 'Una User', 'una@example.test');
        $directory->add(4, 'Max Manager', 'max@example.test');
        $this->grant($app, [Module::PERMISSION_AR_RECORDS_READ]);

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/ar/assignees');

        self::assertSame(200, $status);
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame(['Max Manager', 'Una User'], array_column($data, 'full_name'));
        self::assertSame(['4', '9'], array_column($data, 'id'));
    }

    public function test_the_option_lists_are_seeded_on_first_read_and_a_reader_cannot_write_them(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AR_RECORDS_READ, Module::PERMISSION_AR_RECORDS_WRITE]);

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/ar/settings/options');
        self::assertSame(200, $status);
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['category']);
        self::assertIsArray($data['method']);
        self::assertCount(12, $data['category']);
        self::assertCount(2, $data['method']);

        [$status, $json] = $this->call($app, 'POST', '/api/v1/finance/ar/settings/options/category', ['label' => 'Nope']);
        self::assertSame(403, $status, 'an operator reads the lists but does not change them (source test 13e)');
        self::assertFalse($json['success']);
    }

    public function test_an_administrator_runs_the_whole_settings_cycle_through_the_routes(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AR_RECORDS_READ, Module::PERMISSION_AR_ADMIN]);
        $base = '/api/v1/finance/ar/settings/options';

        [$status, $json] = $this->call($app, 'POST', $base . '/category', ['label' => 'Warranty Claim', 'description' => 'A claim', 'color' => 'orange']);
        self::assertSame(201, $status);
        $created = $json['data'];
        self::assertIsArray($created);
        self::assertSame('warranty_claim', $created['value']);
        self::assertSame(11, $created['sort_order']);

        [$status, $json] = $this->call($app, 'PATCH', $base . '/category/warranty_claim', ['label' => 'Warranty']);
        self::assertSame(200, $status);
        $patched = $json['data'];
        self::assertIsArray($patched);
        self::assertSame('Warranty', $patched['label']);

        [$status, $json] = $this->call($app, 'PUT', $base . '/method/order', ['values' => ['email', 'phone']]);
        self::assertSame(200, $status);
        $order = $json['data'];
        self::assertIsArray($order);
        self::assertSame(['email', 'phone'], array_column($order, 'value'));

        [$status, $json] = $this->call($app, 'DELETE', $base . '/category/warranty_claim');
        self::assertSame(200, $status);
        self::assertSame(['deleted' => true, 'archived' => false, 'usage_count' => 0], $json['data']);
    }

    public function test_the_sources_refusals_are_422_with_the_ar_code_and_its_words(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AR_ADMIN]);
        $base = '/api/v1/finance/ar/settings/options';

        [$status, $json] = $this->call($app, 'DELETE', $base . '/category/other');
        self::assertSame(422, $status);
        $error = self::error($json);
        self::assertSame('FINANCE_AR_INPUT_INVALID', $error['code']);
        self::assertSame("'Other' is built in and cannot be removed", $error['message']);

        [$status, $json] = $this->call($app, 'PATCH', $base . '/method/phone', ['active' => false]);
        self::assertSame(422, $status);
        self::assertSame("'Phone' is built in and cannot be archived", self::error($json)['message']);

        [$status, $json] = $this->call($app, 'POST', $base . '/category', ['label' => 'Other']);
        self::assertSame(422, $status);
        self::assertSame("'Other' is already in the list", self::error($json)['message']);

        [$status, $json] = $this->call($app, 'POST', $base . '/nonsense', ['label' => 'X']);
        self::assertSame(422, $status);
        self::assertSame("Unknown list: 'nonsense'", self::error($json)['message']);

        [$status, $json] = $this->call($app, 'PUT', $base . '/method/order', ['values' => ['phone']]);
        self::assertSame(422, $status);
        self::assertSame('The new order must list every option exactly once', self::error($json)['message']);

        [$status] = $this->call($app, 'POST', $base . '/category');
        self::assertSame(422, $status, 'no body reads as {} and has no name');
    }

    public function test_a_value_called_order_is_still_a_value_and_the_order_route_is_a_put(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AR_ADMIN]);
        $base = '/api/v1/finance/ar/settings/options';

        [$status, $json] = $this->call($app, 'POST', $base . '/method', ['label' => 'Order']);
        self::assertSame(201, $status);
        $created = $json['data'];
        self::assertIsArray($created);
        self::assertSame('order', $created['value']);

        [$status] = $this->call($app, 'PATCH', $base . '/method/order', ['label' => 'Order form']);
        self::assertSame(200, $status);
        [$status, $json] = $this->call($app, 'DELETE', $base . '/method/order');
        self::assertSame(200, $status);
        $deleted = $json['data'];
        self::assertIsArray($deleted);
        self::assertTrue($deleted['deleted']);
    }

    public function test_a_bad_time_zone_is_refused_at_startup_and_a_zone_is_configurable(): void
    {
        $app = $this->app(self::ON + ['FINANCE_AR_TIMEZONE' => 'Australia/Perth']);
        $this->grant($app, [Module::PERMISSION_AR_ACCESS]);
        [, $json] = $this->call($app, 'GET', '/api/v1/finance/ar/me');
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame('Australia/Perth', $data['timezone']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FINANCE_AR_TIMEZONE');
        $this->app(self::ON + ['FINANCE_AR_TIMEZONE' => 'Mars/Olympus']);
    }
}
