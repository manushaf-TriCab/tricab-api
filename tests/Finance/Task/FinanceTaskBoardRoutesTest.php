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
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Identity\Contract\UserRepository;

/** The project board's routes through the real app on the in-memory stores (13 Oct 2026): shapes, gates, the review rule. */
final class FinanceTaskBoardRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/task';

    private const MEMBER = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN];

    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']);
        $directory = $this->app->getContainer()->get(FinanceTaskMemberDirectory::class);
        self::assertInstanceOf(InMemoryFinanceTaskMemberDirectory::class, $directory);
        $directory->add(1, 'Dev User', 'm1@example.test');
        $directory->add(9, 'Max Manager', 'm9@example.test', TaskMember::ROLE_MANAGER);
        $this->as([...self::MEMBER, Module::PERMISSION_TASK_SETUP_MANAGE]);
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

    /**
     * @param array<string, mixed> $json
     * @return array<array-key, mixed>
     */
    private static function data(array $json): array
    {
        self::assertTrue($json['success'], json_encode($json, JSON_THROW_ON_ERROR));
        self::assertIsArray($json['data']);

        return $json['data'];
    }

    public function test_a_template_makes_a_project_with_its_groups_and_tasks_and_the_board_reads_back(): void
    {
        [$status, $json] = $this->call('POST', '/project-templates', [
            'name' => 'Odoo rollout',
            'groups' => [['name' => 'Odoo Studying', 'tasks' => [['name' => 'Sales Module', 'priority' => 'critical', 'preparer_id' => '1', 'due_offset_days' => 3]]]],
        ]);
        self::assertSame(201, $status);
        $template = self::data($json);
        self::assertIsString($template['id']);

        [$status, $json] = $this->call('POST', '/project-templates/' . $template['id'] . '/apply', ['name' => 'Odoo Project', 'start_date' => '2026-11-02']);
        self::assertSame(201, $status);
        $made = self::data($json);
        self::assertSame(1, $made['tasks_created']);
        self::assertIsArray($made['project']);
        self::assertIsString($made['project']['id']);
        $projectId = $made['project']['id'];

        [$status, $json] = $this->call('GET', '/projects/' . $projectId . '/groups');
        self::assertSame(200, $status);
        $groups = self::data($json);
        self::assertSame(['Odoo Studying'], array_column($groups, 'name'));

        $tasks = self::data($this->call('GET', '/tasks')[1]);
        self::assertCount(1, $tasks);
        self::assertIsArray($tasks[0]);
        self::assertSame('critical', $tasks[0]['priority']);
        self::assertSame('2026-11-05', $tasks[0]['due_date']);
        self::assertSame('9', $tasks[0]['reviewer_id'], 'the default reviewer, a Manager');
        self::assertIsArray($groups[0]);
        self::assertSame($groups[0]['id'], $tasks[0]['group_id']);

        // The preparer sends it for review; Done is the reviewer's.
        $this->as(self::MEMBER);
        self::assertIsString($tasks[0]['id']);
        [$status] = $this->call('PATCH', '/tasks/' . $tasks[0]['id'], ['status' => 'completed']);
        self::assertSame(403, $status);
        // Send for review is a button, not a status (13 Oct 2026); mail is off in tests.
        [$status, $json] = $this->call('POST', '/tasks/' . $tasks[0]['id'] . '/request-review');
        self::assertSame(200, $status);
        self::assertSame(['status' => 'suppressed'], self::data($json));
        [$status, $json] = $this->call('PATCH', '/tasks/' . $tasks[0]['id'], ['status' => 'on_hold']);
        self::assertSame(200, $status);
        self::assertSame('on_hold', self::data($json)['status']);
    }

    public function test_a_member_reads_the_board_but_cannot_change_its_shape(): void
    {
        $projectId = self::data($this->call('POST', '/projects', ['name' => 'Audit'])[1])['id'];
        self::assertIsString($projectId);
        $group = self::data($this->call('POST', '/projects/' . $projectId . '/groups', ['name' => 'Fieldwork'])[1]);
        self::assertIsString($group['id']);

        $this->as(self::MEMBER);
        self::assertSame(200, $this->call('GET', '/projects/' . $projectId . '/groups')[0]);
        self::assertSame(200, $this->call('GET', '/project-templates')[0]);
        self::assertSame(403, $this->call('POST', '/projects/' . $projectId . '/groups', ['name' => 'Mine'])[0]);
        self::assertSame(403, $this->call('PATCH', '/groups/' . $group['id'], ['name' => 'Mine'])[0]);
        self::assertSame(403, $this->call('DELETE', '/groups/' . $group['id'])[0]);
        self::assertSame(403, $this->call('POST', '/project-templates', ['name' => 'Mine'])[0]);
    }

    public function test_a_member_uses_a_project_template(): void
    {
        $template = self::data($this->call('POST', '/project-templates', [
            'name' => 'Weekly tasks',
            'groups' => [['name' => 'AP', 'tasks' => [['name' => 'Pay run', 'preparer_ids' => ['1']]]]],
        ])[1]);
        self::assertIsString($template['id']);

        $this->as(self::MEMBER);
        [$status, $json] = $this->call('POST', '/project-templates/' . $template['id'] . '/apply', ['name' => 'Weekly tasks W19', 'start_date' => '2026-11-02']);
        self::assertSame(201, $status);
        $made = self::data($json);
        self::assertSame(1, $made['tasks_created']);
        self::assertIsArray($made['project']);
        self::assertSame('Weekly tasks W19', $made['project']['name']);
        // Editing and deleting a template stay a Manager's.
        self::assertSame(403, $this->call('PATCH', '/project-templates/' . $template['id'], ['name' => 'Mine'])[0]);
        self::assertSame(403, $this->call('DELETE', '/project-templates/' . $template['id'])[0]);
    }

    public function test_anyone_saves_a_project_as_a_template_with_only_the_tasks_they_see(): void
    {
        $projectId = self::data($this->call('POST', '/projects', ['name' => 'Audit'])[1])['id'];
        self::assertIsString($projectId);
        self::assertSame(201, $this->call('POST', '/tasks', ['name' => 'Mine', 'project_id' => $projectId, 'preparer_ids' => ['1']])[0]);
        self::assertSame(201, $this->call('POST', '/tasks', ['name' => 'Theirs', 'project_id' => $projectId, 'preparer_ids' => ['9'], 'reviewer_ids' => ['9']])[0]);

        $this->as(self::MEMBER);
        [$status, $json] = $this->call('POST', '/projects/' . $projectId . '/save-as-template', ['name' => 'Audit template']);
        self::assertSame(201, $status);
        $template = self::data($json);
        self::assertSame('Audit template', $template['name']);
        self::assertIsArray($template['groups']);
        $names = [];
        foreach ($template['groups'] as $group) {
            self::assertIsArray($group);
            self::assertIsArray($group['tasks']);
            foreach ($group['tasks'] as $task) {
                self::assertIsArray($task);
                $names[] = $task['name'];
            }
        }
        self::assertSame(['Mine'], $names, 'a task the member neither prepares nor reviews stays out (decision D15)');
    }

    public function test_unknown_rows_are_404_and_a_bad_template_is_422(): void
    {
        self::assertSame(404, $this->call('GET', '/projects/nope/groups')[0]);
        self::assertSame(404, $this->call('PATCH', '/groups/nope', ['name' => 'x'])[0]);
        self::assertSame(404, $this->call('POST', '/project-templates/nope/apply', ['name' => 'x'])[0]);
        [$status, $json] = $this->call('POST', '/project-templates', ['name' => 'Bad', 'groups' => [['name' => 'G', 'tasks' => [['name' => 'T', 'priority' => 'urgent']]]]]);
        self::assertSame(422, $status);
        self::assertFalse($json['success']);
    }

    public function test_project_groups_hold_projects_and_deleting_one_ungroups_them(): void
    {
        [$status, $json] = $this->call('POST', '/project-groups', ['name' => 'Odoo', 'color' => '#6366F1']);
        self::assertSame(201, $status);
        $group = self::data($json);
        self::assertIsString($group['id']);
        self::assertSame(0, $group['sort_order']);
        self::assertSame(1, self::data($this->call('POST', '/project-groups', ['name' => 'Audit'])[1])['sort_order']);

        [$status, $json] = $this->call('POST', '/projects', ['name' => 'Odoo Project', 'group_id' => $group['id']]);
        self::assertSame(201, $status);
        $project = self::data($json);
        self::assertSame($group['id'], $project['group_id']);
        self::assertIsString($project['id']);
        [$status, $json] = $this->call('PATCH', '/projects/' . $project['id'], ['group_id' => 'nope']);
        self::assertSame(422, $status);
        self::assertSame('That group does not exist', ((array) $json['error'])['message']);

        [$status, $json] = $this->call('PATCH', '/project-groups/' . $group['id'], ['name' => 'Odoo rollout', 'sort_order' => 3]);
        self::assertSame(200, $status);
        self::assertSame('Odoo rollout', self::data($json)['name']);
        self::assertSame(['Audit', 'Odoo rollout'], array_column(self::data($this->call('GET', '/project-groups')[1]), 'name'));

        [$status, $json] = $this->call('DELETE', '/project-groups/' . $group['id']);
        self::assertSame(200, $status);
        self::assertSame(['deleted' => true], self::data($json));
        $projects = self::data($this->call('GET', '/projects')[1]);
        self::assertCount(1, $projects);
        self::assertIsArray($projects[0]);
        self::assertNull($projects[0]['group_id']);
        self::assertSame(404, $this->call('PATCH', '/project-groups/' . $group['id'], ['name' => 'x'])[0]);
        self::assertSame(404, $this->call('DELETE', '/project-groups/' . $group['id'])[0]);
    }

    public function test_a_member_reads_project_groups_but_cannot_change_them(): void
    {
        $group = self::data($this->call('POST', '/project-groups', ['name' => 'Odoo'])[1]);
        self::assertIsString($group['id']);

        $this->as(self::MEMBER);
        self::assertSame(200, $this->call('GET', '/project-groups')[0]);
        self::assertSame(403, $this->call('POST', '/project-groups', ['name' => 'Mine'])[0]);
        self::assertSame(403, $this->call('PATCH', '/project-groups/' . $group['id'], ['name' => 'Mine'])[0]);
        self::assertSame(403, $this->call('DELETE', '/project-groups/' . $group['id'])[0]);
    }
}
