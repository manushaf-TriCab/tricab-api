<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * Tri-Task's routes through the real app on the in-memory stores: the switch, the gates,
 * and what each action answers. The Tri-Task routes grow with each task (T2.5 onward).
 */
final class FinanceTaskWiringTest extends TestCase
{
    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function app(array $env = ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']): App
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
     * @param App<ContainerInterface> $app
     */
    private function directory(App $app): InMemoryFinanceTaskMemberDirectory
    {
        $directory = $app->getContainer()->get(FinanceTaskMemberDirectory::class);
        self::assertInstanceOf(InMemoryFinanceTaskMemberDirectory::class, $directory);

        return $directory;
    }

    public function test_with_the_task_switch_off_nothing_of_tri_task_is_reachable(): void
    {
        foreach ([['FINANCE_MODULE_ENABLED' => 'true'], ['FINANCE_TASK_ENABLED' => 'true'], []] as $env) {
            $app = $this->app($env);
            $this->grant($app, [Module::PERMISSION_TASK_ACCESS, ...Module::TASK_PERMISSIONS]);
            [$status] = $this->call($app, 'GET', '/api/v1/finance/task/me');
            self::assertSame(404, $status, json_encode($env, JSON_THROW_ON_ERROR));
            [$status] = $this->call($app, 'GET', '/api/v1/finance/task/members');
            self::assertSame(404, $status);
        }
    }

    public function test_me_answers_the_portal_permission_strings_in_the_platform_envelope(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_TASK_ACCESS, Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_READ_ALL]);

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/task/me');

        self::assertSame(200, $status);
        self::assertTrue($json['success']);
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame(['task.tasks.read', 'task.tasks.read_all'], $data['permissions']);
        self::assertSame('manager', $data['accessRole']);
        self::assertSame('Australia/Melbourne', $data['timezone']);
        self::assertSame('1', $data['id']);
    }

    public function test_me_is_refused_without_the_app_permission(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_TASK_TASKS_READ]);

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/task/me');

        self::assertSame(403, $status);
        self::assertFalse($json['success']);
    }

    public function test_members_lists_the_directory(): void
    {
        $app = $this->app();
        $this->directory($app)->add(9, 'Una User', 'una@example.test');
        $this->directory($app)->add(4, 'Max Manager', 'max@example.test', TaskMember::ROLE_MANAGER);
        $this->grant($app, [Module::PERMISSION_TASK_TASKS_READ]);

        [$status, $json] = $this->call($app, 'GET', '/api/v1/finance/task/members');

        self::assertSame(200, $status);
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame(['Max Manager', 'Una User'], array_column($data, 'name'));
        self::assertSame(['4', '9'], array_column($data, 'id'));
    }

    public function test_the_job_title_route_updates_a_member_and_refuses_a_stranger_and_a_long_title(): void
    {
        $app = $this->app();
        $this->directory($app)->add(9, 'Una User', 'una@example.test');
        $this->grant($app, [Module::PERMISSION_TASK_SETUP_MANAGE]);

        [$status, $json] = $this->call($app, 'PATCH', '/api/v1/finance/task/members/9/profile', ['job_title' => ' Accountant ']);
        self::assertSame(200, $status);
        self::assertSame(['user_id' => '9', 'job_title' => 'Accountant'], $json['data']);

        [$status, $json] = $this->call($app, 'PATCH', '/api/v1/finance/task/members/77/profile', ['job_title' => 'x']);
        self::assertSame(404, $status);
        $error = $json['error'];
        self::assertIsArray($error);
        self::assertSame('FINANCE_TASK_NOT_FOUND', $error['code']);
        self::assertSame('Member not found', $error['message']);

        [$status] = $this->call($app, 'PATCH', '/api/v1/finance/task/members/not-a-number/profile', ['job_title' => 'x']);
        self::assertSame(404, $status);

        [$status, $json] = $this->call($app, 'PATCH', '/api/v1/finance/task/members/9/profile', ['job_title' => str_repeat('a', 201)]);
        self::assertSame(422, $status);
        $error = $json['error'];
        self::assertIsArray($error);
        self::assertSame('FINANCE_TASK_INPUT_INVALID', $error['code']);
    }

    public function test_the_container_holds_the_in_memory_directory_by_default_and_a_configurable_zone(): void
    {
        $app = $this->app(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true', 'FINANCE_TASK_TIMEZONE' => 'Australia/Perth']);
        self::assertInstanceOf(Container::class, $app->getContainer());
        $this->grant($app, [Module::PERMISSION_TASK_ACCESS]);

        [, $json] = $this->call($app, 'GET', '/api/v1/finance/task/me');

        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame('Australia/Perth', $data['timezone']);
    }

    public function test_a_bad_time_zone_is_refused_at_startup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FINANCE_TASK_TIMEZONE');
        $this->app(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true', 'FINANCE_TASK_TIMEZONE' => 'Mars/Olympus']);
    }
}
