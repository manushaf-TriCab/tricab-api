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
use TriCab\Core\Finance\Task\Contract\TaskDrafter;
use TriCab\Core\Finance\Task\Domain\TaskAiFailed;
use TriCab\Core\Finance\Task\Domain\TaskDraftRequest;
use TriCab\Core\Finance\Task\Infrastructure\HeuristicTaskDrafter;
use TriCab\Core\Finance\Task\Infrastructure\UnavailableTaskDrafter;
use TriCab\Core\Identity\Contract\UserRepository;

/** POST /ai/draft through the real app: the gate, the shapes, the failures, and that it writes nothing. */
final class FinanceTaskAiDraftRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/task';

    /** @var App<ContainerInterface> */
    private App $app;

    private HeuristicTaskDrafter $drafter;

    private Container $container;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $this->container = $container;
        $directory = $container->get(FinanceTaskMemberDirectory::class);
        self::assertInstanceOf(InMemoryFinanceTaskMemberDirectory::class, $directory);
        $directory->add(1, 'Dev User', 'dev@example.test');
        $directory->add(2, 'Mia Chen', 'mia@example.test');
        $this->grant([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE]);
        $this->drafter = new HeuristicTaskDrafter();
        $container->set(TaskDrafter::class, $this->drafter);
    }

    /** @param list<string> $permissions */
    private function grant(array $permissions): void
    {
        $users = $this->container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(string $path, array $body): array
    {
        $response = $this->app->handle(TestAppFactory::request('POST', self::BASE . $path)->withParsedBody($body));

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    /** @return list<array<string, mixed>> */
    private function tasks(): array
    {
        $response = $this->app->handle(TestAppFactory::request('GET', self::BASE . '/tasks'));
        $json = TestAppFactory::json($response);
        $data = $json['data'];
        self::assertIsArray($data);

        $out = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $out[] = $typed;
        }

        return $out;
    }

    public function test_a_summary_comes_back_as_a_draft_of_tasks_and_nothing_is_written(): void
    {
        [$status, $json] = $this->post('/ai/draft', ['text' => "Mia to do the QLD car insurance claim by Friday\nLin reviews"]);

        self::assertSame(200, $status);
        self::assertTrue($json['success']);
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame(['project', 'tasks', 'warnings', 'injection_suspected', 'model'], array_keys($data));
        self::assertNull($data['project']);
        $tasks = $data['tasks'];
        self::assertIsArray($tasks);
        self::assertCount(2, $tasks);
        self::assertSame('2', ((array) $tasks[0])['preparer_id']);
        self::assertNotNull(((array) $tasks[0])['due_date']);
        self::assertSame([], $this->tasks(), 'a draft writes nothing');
        self::assertNotNull($this->drafter->lastRequest);
        self::assertFalse($this->drafter->lastRequest->mayCreateProject);
    }

    public function test_someone_with_setup_manage_may_be_offered_a_project(): void
    {
        $this->grant([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_SETUP_MANAGE]);
        $this->drafter->next = ['project' => ['name' => 'FY27 budget'], 'tasks' => [['name' => 'Collect forecasts', 'in_new_project' => true]]];

        [$status, $json] = $this->post('/ai/draft', ['text' => 'Start the FY27 budget']);

        self::assertSame(200, $status);
        $data = (array) $json['data'];
        self::assertSame('FY27 budget', ((array) $data['project'])['name']);
        self::assertTrue(((array) ((array) $data['tasks'])[0])['in_new_project']);
        self::assertTrue($this->drafter->lastRequest?->mayCreateProject);
    }

    public function test_without_the_create_permission_it_is_a_403_and_the_model_is_not_called(): void
    {
        $this->grant([Module::PERMISSION_TASK_TASKS_READ]);

        [$status] = $this->post('/ai/draft', ['text' => 'Do a thing']);

        self::assertSame(403, $status);
        self::assertNull($this->drafter->lastRequest);
    }

    public function test_no_text_is_a_422_and_never_reaches_the_model(): void
    {
        [$status, $json] = $this->post('/ai/draft', []);

        self::assertSame(422, $status);
        self::assertSame('FINANCE_TASK_INPUT_INVALID', ((array) $json['error'])['code']);
        self::assertSame('Write or dictate a summary first', ((array) $json['error'])['message']);
        self::assertNull($this->drafter->lastRequest);
    }

    public function test_not_set_up_is_a_503_with_a_plain_message(): void
    {
        $this->container->set(TaskDrafter::class, new UnavailableTaskDrafter());

        [$status, $json] = $this->post('/ai/draft', ['text' => 'Do a thing']);

        self::assertSame(503, $status);
        self::assertFalse($json['success']);
        self::assertSame('FINANCE_TASK_UNAVAILABLE', ((array) $json['error'])['code']);
        $message = ((array) $json['error'])['message'];
        self::assertIsString($message);
        self::assertStringContainsString('not set up', $message);
    }

    public function test_a_model_failure_is_a_502_and_the_rest_of_tri_task_still_works(): void
    {
        $this->container->set(TaskDrafter::class, new class() implements TaskDrafter {
            public function draft(TaskDraftRequest $request): array
            {
                throw new TaskAiFailed('The AI draft failed: the model could not be reached or took too long');
            }

            public function modelName(): string
            {
                return '';
            }
        });

        [$status, $json] = $this->post('/ai/draft', ['text' => 'Do a thing']);

        self::assertSame(502, $status);
        self::assertSame('FINANCE_TASK_AI_FAILED', ((array) $json['error'])['code']);
        self::assertSame('The AI draft failed: the model could not be reached or took too long', ((array) $json['error'])['message']);

        [$status] = $this->post('/tasks', ['name' => 'Still works', 'preparer_id' => '1']);
        self::assertSame(201, $status);
    }

    public function test_a_draft_can_then_be_created_through_the_ordinary_task_endpoint(): void
    {
        $this->grant([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_CREATE]);
        [, $json] = $this->post('/ai/draft', ['text' => 'Mia to reconcile the bank by 2027-01-15']);
        $task = ((array) ((array) $json['data'])['tasks'])[0];
        self::assertIsArray($task);

        [$status, $created] = $this->post('/tasks', [
            'name' => $task['name'], 'preparer_id' => $task['preparer_id'], 'due_date' => $task['due_date'],
            'priority' => $task['priority'], 'category' => $task['category'], 'status' => $task['status'],
        ]);

        self::assertSame(201, $status);
        self::assertSame('Mia to reconcile the bank by 2027-01-15', ((array) $created['data'])['name']);
        self::assertCount(1, $this->tasks());
    }
}
