<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Identity\Contract\UserRepository;

/** The reference-list routes through the real app on the in-memory store (T2.3): envelopes, statuses, bodies. */
final class FinanceTaskSetupRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/task';

    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']);
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS, Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_SETUP_MANAGE], ['EMPLOYEE']);
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    /** @param array<string, mixed> $json */
    private static function data(array $json): mixed
    {
        self::assertTrue($json['success'], json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data'];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function error(array $json): array
    {
        self::assertFalse($json['success']);
        $error = $json['error'];
        self::assertIsArray($error);
        $out = [];
        foreach ($error as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private function idOf(mixed $row): string
    {
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    public function test_a_project_goes_through_create_patch_bin_restore_and_permanent_delete(): void
    {
        [$status, $json] = $this->call('POST', '/projects', ['name' => 'Close', 'status' => 'active']);
        self::assertSame(201, $status);
        $id = $this->idOf(self::data($json));

        [$status, $json] = $this->call('PATCH', '/projects/' . $id, ['description' => 'Q1']);
        self::assertSame(200, $status);
        $data = self::data($json);
        self::assertIsArray($data);
        self::assertSame('Q1', $data['description']);

        [$status, $json] = $this->call('DELETE', '/projects/' . $id);
        self::assertSame(200, $status);
        self::assertSame(['deleted' => true, 'permanent' => false], self::data($json));

        [, $json] = $this->call('GET', '/projects');
        self::assertSame([], self::data($json));
        $request = TestAppFactory::request('GET', self::BASE . '/projects')->withQueryParams(['deleted' => '1']);
        $bin = TestAppFactory::json($this->app->handle($request));
        self::assertSame(['Close'], array_column((array) self::data($bin), 'name'));

        [$status] = $this->call('POST', '/projects/' . $id . '/restore');
        self::assertSame(200, $status);
        [$status] = $this->call('DELETE', '/projects/' . $id);
        $request = TestAppFactory::request('DELETE', self::BASE . '/projects/' . $id)->withQueryParams(['permanent' => '1']);
        $permanent = $this->app->handle($request);
        self::assertSame(200, $permanent->getStatusCode());
        self::assertSame(['deleted' => true, 'permanent' => true], self::data(TestAppFactory::json($permanent)));
    }

    public function test_refusals_use_the_platform_envelope_and_codes(): void
    {
        [$status, $json] = $this->call('POST', '/projects', ['name' => '']);
        self::assertSame(422, $status);
        self::assertSame(['code' => 'FINANCE_TASK_INPUT_INVALID', 'message' => 'Name is required'], self::error($json));

        $this->call('POST', '/projects', ['name' => 'Close']);
        [$status, $json] = $this->call('POST', '/projects', ['name' => 'CLOSE']);
        self::assertSame(409, $status);
        self::assertSame(['code' => 'FINANCE_TASK_CONFLICT', 'message' => 'A project with that name already exists'], self::error($json));

        [$status, $json] = $this->call('PATCH', '/projects/ffffffffffffffffffffffffffffffff', ['name' => 'x']);
        self::assertSame(404, $status);
        self::assertSame('Project not found', self::error($json)['message']);

        [$status] = $this->call('DELETE', '/entities/ffffffffffffffffffffffffffffffff');
        self::assertSame(404, $status);
    }

    public function test_templates_check_members_and_delete_hard(): void
    {
        $directory = $this->app->getContainer()->get(FinanceTaskMemberDirectory::class);
        self::assertInstanceOf(InMemoryFinanceTaskMemberDirectory::class, $directory);
        $directory->add(9, 'Una User', 'una@example.test');

        [$status, $json] = $this->call('POST', '/templates', ['name' => 'Bank rec', 'preparer_id' => '9', 'progress' => 10]);
        self::assertSame(201, $status);
        $data = self::data($json);
        self::assertIsArray($data);
        self::assertSame('9', $data['preparer_id']);

        [$status, $json] = $this->call('POST', '/templates', ['name' => 'Other', 'reviewer_id' => '404']);
        self::assertSame(422, $status);
        self::assertSame('The preparer and reviewer must be members of Tri-Task', self::error($json)['message']);

        [, $json] = $this->call('GET', '/templates');
        self::assertSame(['Bank rec'], array_column((array) self::data($json), 'name'));
        [$status, $json] = $this->call('DELETE', '/templates/' . $this->idOf($data));
        self::assertSame(200, $status);
        self::assertSame(['deleted' => true], self::data($json));
    }

    public function test_areas_take_a_bulk_body_as_an_object_or_a_bare_list(): void
    {
        [$status, $json] = $this->call('POST', '/statement-areas/bulk', ['items' => [['name' => 'Cash'], ['name' => 'Equity']]]);
        self::assertSame(201, $status);
        self::assertSame(['Cash', 'Equity'], array_column((array) self::data($json), 'name'));

        [$status, $json] = $this->call('POST', '/statement-areas/bulk', [['name' => 'cash'], ['name' => 'Revenue']]);
        self::assertSame(201, $status);
        self::assertSame(['Revenue'], array_column((array) self::data($json), 'name'));

        [$status, $json] = $this->call('POST', '/statement-areas/bulk', []);
        self::assertSame(422, $status);
        self::assertSame('Send a list of statement areas', self::error($json)['message']);

        [$status, $json] = $this->call('POST', '/statement-areas', ['name' => 'CASH']);
        self::assertSame(409, $status);
        self::assertSame('A statement area with that name already exists', self::error($json)['message']);

        [, $json] = $this->call('GET', '/statement-areas');
        self::assertSame(['Cash', 'Equity', 'Revenue'], array_column((array) self::data($json), 'name'));
    }

    public function test_entities_create_patch_and_delete(): void
    {
        [$status, $json] = $this->call('POST', '/entities', ['name' => 'Alpha Pty', 'code' => 'AP']);
        self::assertSame(201, $status);
        $id = $this->idOf(self::data($json));
        [$status, $json] = $this->call('PATCH', '/entities/' . $id, ['is_active' => false]);
        self::assertSame(200, $status);
        $data = self::data($json);
        self::assertIsArray($data);
        self::assertFalse($data['is_active']);
        [$status, $json] = $this->call('DELETE', '/entities/' . $id);
        self::assertSame(200, $status);
        self::assertSame(['deleted' => true], self::data($json));
    }
}
