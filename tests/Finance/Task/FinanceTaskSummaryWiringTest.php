<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Identity\Contract\UserRepository;

/** The daily summary is registered with the job runner only while Tri-Task is on, and the recipients' routes answer their shapes (T2.9). */
final class FinanceTaskSummaryWiringTest extends TestCase
{
    /**
     * @param array<string, string> $env
     * @return list<string>
     */
    private function jobNames(array $env): array
    {
        $registry = TestAppFactory::create($env)->getContainer()->get(FinanceJobRegistry::class);
        self::assertInstanceOf(FinanceJobRegistry::class, $registry);

        return array_map(static fn ($job): string => $job->name(), $registry->all());
    }

    public function test_the_job_is_registered_only_when_the_module_and_tri_task_are_on(): void
    {
        self::assertSame(['task.daily-summary'], $this->jobNames(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']));
        foreach ([[], ['FINANCE_MODULE_ENABLED' => 'true'], ['FINANCE_TASK_ENABLED' => 'true']] as $env) {
            self::assertSame([], $this->jobNames($env), json_encode($env, JSON_THROW_ON_ERROR));
        }
    }

    public function test_the_job_is_the_registrys_first_app_job_and_sits_beside_odoo_when_that_is_configured(): void
    {
        $names = $this->jobNames([
            'FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true',
            'ODOO_URL' => 'https://odoo.example.test', 'ODOO_API_KEY' => 'dummy', 'ODOO_COMPANY_IDS' => '2',
        ]);

        self::assertSame(['odoo.sync', 'task.daily-summary'], $names);
    }

    /** @return App<ContainerInterface> */
    private function app(): App
    {
        $app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']);
        $users = $app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS, Module::PERMISSION_TASK_SETTINGS_MANAGE], ['EMPLOYEE']);

        return $app;
    }

    public function test_the_recipient_routes_add_list_conflict_and_delete(): void
    {
        $app = $this->app();
        $call = static function (string $method, string $path, ?array $body = null) use ($app): array {
            $request = TestAppFactory::request($method, '/api/v1/finance/task/settings/report-recipients' . $path);
            if ($body !== null) {
                $request = $request->withParsedBody($body);
            }
            $response = $app->handle($request);

            return [$response->getStatusCode(), TestAppFactory::json($response)];
        };

        [$status, $json] = $call('POST', '', ['email' => 'Boss@Example.test', 'name' => 'Boss']);
        self::assertSame(201, $status);
        $row = $json['data'];
        self::assertIsArray($row);
        self::assertSame('boss@example.test', $row['email']);
        [$status, $json] = $call('POST', '', ['email' => 'boss@example.test']);
        self::assertSame(409, $status);
        self::assertSame('That address is already a recipient', ((array) $json['error'])['message']);
        [$status, $json] = $call('POST', '', ['email' => 'nope']);
        self::assertSame(422, $status);
        [, $json] = $call('GET', '');
        self::assertSame(['boss@example.test'], array_column((array) $json['data'], 'email'));
        [$status, $json] = $call('DELETE', '/' . Typed::string($row['id']));
        self::assertSame(200, $status);
        self::assertSame(['deleted' => true], $json['data']);
        [$status] = $call('DELETE', '/' . Typed::string($row['id']));
        self::assertSame(404, $status);
    }
}
