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

/** The task routes through the real app on the in-memory store (T2.4): visibility, envelopes, blockers, bulk, the Bin. */
final class FinanceTaskRegisterRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/task';

    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']);
        $directory = $this->app->getContainer()->get(FinanceTaskMemberDirectory::class);
        self::assertInstanceOf(InMemoryFinanceTaskMemberDirectory::class, $directory);
        foreach ([1 => 'Dev User', 9 => 'Una User'] as $id => $name) {
            $directory->add($id, $name, 'm' . $id . '@example.test');
        }
        $this->as([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN]);
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): void
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @param array<string, string> $query
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $request = TestAppFactory::request($method, self::BASE . $path)->withQueryParams($query);
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

    private static function id(mixed $row): string
    {
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    public function test_several_tasks_go_to_the_bin_together_and_come_back_together(): void
    {
        $a = self::id(self::data($this->call('POST', '/tasks', ['name' => 'Bank rec', 'preparer_id' => '1'])[1]));
        $b = self::id(self::data($this->call('POST', '/tasks', ['name' => 'Payroll', 'preparer_id' => '1'])[1]));

        [$status, $json] = $this->call('POST', '/tasks/bulk-delete', ['ids' => [$a, $b, $a]]);
        self::assertSame(200, $status);
        self::assertSame(['deleted' => 2, 'ids' => [$a, $b]], self::data($json));
        self::assertSame([], self::data($this->call('GET', '/tasks')[1]));
        self::assertEqualsCanonicalizing(['Bank rec', 'Payroll'], array_column((array) self::data($this->call('GET', '/tasks', query: ['deleted' => '1'])[1]), 'name'));

        // Undo: both come back.
        [$status, $json] = $this->call('POST', '/tasks/bulk-restore', ['ids' => [$a, $b]]);
        self::assertSame(200, $status);
        self::assertSame([false, false], array_column((array) self::data($json), 'is_deleted'));
        self::assertEqualsCanonicalizing(['Bank rec', 'Payroll'], array_column((array) self::data($this->call('GET', '/tasks')[1]), 'name'));
    }

    public function test_a_bulk_delete_is_all_or_nothing_and_needs_ids(): void
    {
        $mine = self::id(self::data($this->call('POST', '/tasks', ['name' => 'Bank rec', 'preparer_id' => '1'])[1]));

        [$status, $json] = $this->call('POST', '/tasks/bulk-delete', ['ids' => [$mine, 'no-such-task']]);
        self::assertSame(404, $status);
        self::assertSame('Task not found', self::error($json)['message']);
        self::assertSame(['Bank rec'], array_column((array) self::data($this->call('GET', '/tasks')[1]), 'name'), 'the batch rolled back');

        [$status] = $this->call('POST', '/tasks/bulk-delete', ['ids' => []]);
        self::assertSame(422, $status);
        [$status] = $this->call('POST', '/tasks/bulk-restore', ['ids' => ['']]);
        self::assertSame(422, $status);

        $this->as([Module::PERMISSION_TASK_TASKS_READ]);
        [$status] = $this->call('POST', '/tasks/bulk-delete', ['ids' => [$mine]]);
        self::assertSame(403, $status);
    }

    public function test_a_member_creates_edits_and_bins_their_own_task_and_the_answer_is_the_envelope(): void
    {
        [$status, $json] = $this->call('POST', '/tasks', ['name' => 'Bank rec', 'preparer_id' => '1', 'due_date' => '2020-01-01']);
        self::assertSame(201, $status);
        $task = self::data($json);
        self::assertIsArray($task);
        self::assertSame('overdue', $task['display_status']);
        $id = self::id($task);

        [$status, $json] = $this->call('PATCH', '/tasks/' . $id, ['progress' => 60]);
        self::assertSame(200, $status);
        self::assertSame(60, ((array) self::data($json))['progress']);

        [, $json] = $this->call('GET', '/tasks');
        self::assertSame(['Bank rec'], array_column((array) self::data($json), 'name'));

        [$status, $json] = $this->call('DELETE', '/tasks/' . $id);
        self::assertSame(['deleted' => true, 'permanent' => false], self::data($json));
        [, $json] = $this->call('GET', '/tasks', null, ['deleted' => '1']);
        self::assertSame(['Bank rec'], array_column((array) self::data($json), 'name'));
        [$status, $json] = $this->call('POST', '/tasks/' . $id . '/restore');
        self::assertSame(200, $status);
        [$status, $json] = $this->call('DELETE', '/tasks/' . $id, null, ['permanent' => '1']);
        self::assertSame(409, $status);
        self::assertSame('Move the task to the Bin before deleting it permanently', self::error($json)['message']);
    }

    public function test_a_task_someone_else_holds_is_a_404_and_a_manager_sees_it(): void
    {
        [$status, $json] = $this->call('POST', '/tasks', ['name' => 'Theirs', 'preparer_id' => '9']);
        self::assertSame(201, $status, 'the creator may allocate to someone else');
        $id = self::id(self::data($json));

        foreach ([['GET', '/tasks/' . $id], ['GET', '/tasks/' . $id . '/history'], ['PATCH', '/tasks/' . $id], ['DELETE', '/tasks/' . $id], ['POST', '/tasks/' . $id . '/restore']] as [$method, $path]) {
            [$status, $json] = $this->call($method, $path, $method === 'PATCH' ? ['progress' => 1] : null);
            self::assertSame(404, $status, $method . ' ' . $path);
            self::assertSame(['code' => 'FINANCE_TASK_NOT_FOUND', 'message' => 'Task not found'], self::error($json));
        }
        [, $json] = $this->call('GET', '/tasks');
        self::assertSame([], self::data($json));

        $this->as([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE_OWN]);
        [$status] = $this->call('GET', '/tasks/' . $id);
        self::assertSame(200, $status);
        [$status, $json] = $this->call('PATCH', '/tasks/' . $id, ['progress' => 1]);
        self::assertSame(403, $status, 'READ_ALL with only UPDATE_OWN may see the task but not change it');
        self::assertSame('FINANCE_TASK_FORBIDDEN', self::error($json)['code']);
        $this->as([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_UPDATE]);
        [$status] = $this->call('PATCH', '/tasks/' . $id, ['progress' => 1]);
        self::assertSame(200, $status);
    }

    public function test_a_blocked_status_change_answers_409_with_the_blockers_inside_the_error(): void
    {
        $gate = self::id(self::data($this->call('POST', '/tasks', ['name' => 'Gate', 'preparer_id' => 1])[1]));
        $task = self::id(self::data($this->call('POST', '/tasks', ['name' => 'Target', 'preparer_id' => 1, 'dependencies' => [$gate]])[1]));

        [$status, $json] = $this->call('PATCH', '/tasks/' . $task, ['status' => 'completed']);

        self::assertSame(409, $status);
        self::assertSame([
            'code' => 'FINANCE_TASK_CONFLICT',
            'message' => 'This task is blocked by unfinished tasks: Gate',
            'blockers' => [['id' => $gate, 'name' => 'Gate']],
        ], self::error($json));
        [$status, $json] = $this->call('PATCH', '/tasks/' . $gate, ['status' => 'overdue']);
        self::assertSame(422, $status);
        self::assertSame('Overdue is worked out from the due date and cannot be set', self::error($json)['message']);
    }

    public function test_bulk_takes_updates_as_an_object_or_a_bare_list_and_is_all_or_nothing(): void
    {
        $a = self::id(self::data($this->call('POST', '/tasks', ['name' => 'A', 'preparer_id' => 1])[1]));
        $b = self::id(self::data($this->call('POST', '/tasks', ['name' => 'B', 'preparer_id' => 1])[1]));

        [$status, $json] = $this->call('POST', '/tasks/bulk', ['updates' => [['id' => $a, 'progress' => 10], ['id' => $b, 'progress' => 20]]]);
        self::assertSame(200, $status);
        self::assertSame([10, 20], array_column((array) self::data($json), 'progress'));
        [$status, $json] = $this->call('POST', '/tasks/bulk', [['id' => $a, 'progress' => 30]]);
        self::assertSame(200, $status);

        [$status, $json] = $this->call('POST', '/tasks/bulk', ['updates' => [['id' => $a, 'progress' => 99], ['id' => $b, 'progress' => 'lots']]]);
        self::assertSame(422, $status);
        [, $json] = $this->call('GET', '/tasks/' . $a);
        self::assertSame(30, ((array) self::data($json))['progress'], 'the first row was rolled back');

        [$status, $json] = $this->call('POST', '/tasks/bulk', []);
        self::assertSame(422, $status);
        self::assertSame('Send a list of tasks to update', self::error($json)['message']);
    }

    public function test_history_and_analytics_need_the_analytics_permission_a_manager_holds_and_answer_their_shapes(): void
    {
        $id = self::id(self::data($this->call('POST', '/tasks', ['name' => 'A', 'preparer_id' => 1])[1]));
        // A Team Member lacks it; a Manager has it since 13 Oct 2026.
        $this->as(Module::TASK_ROLE_PERMISSIONS[Module::ROLE_TASK_USER]);
        [$status] = $this->call('GET', '/history');
        self::assertSame(403, $status);
        [$status] = $this->call('GET', '/analytics');
        self::assertSame(403, $status);

        $this->as(Module::TASK_ROLE_PERMISSIONS[Module::ROLE_TASK_MANAGER]);
        [$status, $json] = $this->call('GET', '/analytics');
        self::assertSame(200, $status);
        $data = self::data($json);
        self::assertIsArray($data);
        self::assertSame(['tasks', 'timezone', 'today'], array_keys($data));
        [, $json] = $this->call('GET', '/history', null, ['to_status' => 'active']);
        self::assertCount(1, (array) self::data($json));
        [, $json] = $this->call('GET', '/history', null, ['to_status' => 'completed']);
        self::assertSame([], self::data($json));
        [$status, $json] = $this->call('GET', '/tasks/' . $id . '/history');
        self::assertSame(200, $status);
        self::assertSame(['active'], array_column((array) self::data($json), 'to_status'));
    }

    public function test_creating_without_a_valid_member_or_a_name_is_a_422(): void
    {
        [$status, $json] = $this->call('POST', '/tasks', ['name' => 'x', 'preparer_id' => '404']);
        self::assertSame(422, $status);
        self::assertSame('The preparer and reviewer must be members of Tri-Task', self::error($json)['message']);
        [$status, $json] = $this->call('POST', '/tasks', []);
        self::assertSame(422, $status);
        self::assertSame('Name is required', self::error($json)['message']);
    }
}
