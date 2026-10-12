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
        [$status, $json] = $this->call('PATCH', '/tasks/' . $tasks[0]['id'], ['status' => 'ready_for_review']);
        self::assertSame(200, $status);
        self::assertSame('ready_for_review', self::data($json)['status']);
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
        self::assertSame(403, $this->call('POST', '/projects/' . $projectId . '/save-as-template', ['name' => 'Mine'])[0]);
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
}
