<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Ai\UndeployedFinanceAiClient;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Identity\Contract\UserRepository;

/** The AI routes through the real app (T2.7): with the service not deployed they answer the source's 502, never an empty draft; with a fake client they answer their shapes. */
final class FinanceTaskAiRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/task';

    /** @var App<ContainerInterface> */
    private App $app;

    private RecordingFinanceAiClient $ai;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $this->ai = new RecordingFinanceAiClient();
        $directory = $container->get(FinanceTaskMemberDirectory::class);
        self::assertInstanceOf(InMemoryFinanceTaskMemberDirectory::class, $directory);
        $directory->add(1, 'Dev User', 'dev@example.test');
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, [
            'account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS, Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE,
            Module::PERMISSION_TASK_SETUP_MANAGE, Module::PERMISSION_TASK_MAIL_SEND,
        ], ['EMPLOYEE']);
    }

    private function useFake(): void
    {
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(FinanceAiClient::class, $this->ai);
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

    public function test_by_default_the_client_is_the_undeployed_one_and_every_route_answers_502_honestly(): void
    {
        self::assertInstanceOf(UndeployedFinanceAiClient::class, $this->app->getContainer()->get(FinanceAiClient::class));

        foreach ([['/ai/task-details', ['name' => 'Bank rec']], ['/ai/statement-areas', ['existing' => []]]] as [$path, $body]) {
            [$status, $json] = $this->post($path, $body);
            self::assertSame(502, $status, $path);
            self::assertFalse($json['success']);
            $error = $json['error'];
            self::assertIsArray($error);
            self::assertSame('FINANCE_TASK_AI_FAILED', $error['code']);
            self::assertSame('The AI draft failed: The finance AI service is not deployed on this platform yet.', $error['message']);
        }
    }

    public function test_the_routes_answer_the_sources_shapes_through_a_fake_client(): void
    {
        $this->useFake();

        [$status, $json] = $this->post('/ai/task-details', ['name' => 'Bank rec']);
        self::assertSame(200, $status);
        self::assertSame(['description' => 'Reconcile the bank.', 'requirements' => '- Sign off'], $json['data']);

        [$status, $json] = $this->post('/ai/statement-areas', []);
        self::assertSame(200, $status);
        self::assertSame(['Revenue'], array_column((array) ((array) $json['data'])['items'], 'name'));

        [$status, $json] = $this->post('/tasks', ['name' => 'Rec', 'preparer_id' => '1']);
        self::assertSame(201, $status);
        $task = $json['data'];
        self::assertIsArray($task);
        self::assertIsString($task['id']);

        [$status, $json] = $this->post('/ai/reminders', ['taskId' => $task['id']]);
        self::assertSame(200, $status);
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertSame(['taskName', 'recipients', 'subject', 'body'], array_keys($data));

        [$status, $json] = $this->post('/ai/task-emails', ['taskIds' => [$task['id']]]);
        self::assertSame(200, $status);
        self::assertSame(['Rec'], array_column((array) ((array) $json['data'])['drafts'], 'taskName'));

        [$status, $json] = $this->post('/ai/email-draft', ['taskId' => $task['id'], 'kind' => 'reminder', 'instruction' => 'Nudge']);
        self::assertSame(200, $status);
        self::assertSame('dev@example.test', ((array) $json['data'])['to']);
    }

    public function test_a_bad_request_is_a_422_and_never_reaches_the_service(): void
    {
        $this->useFake();

        [$status, $json] = $this->post('/ai/task-details', []);
        self::assertSame(422, $status);
        self::assertSame('Task name is required', ((array) $json['error'])['message']);
        [$status, $json] = $this->post('/ai/task-emails', ['taskIds' => []]);
        self::assertSame(422, $status);
        [$status] = $this->post('/ai/email-draft', ['taskId' => 'x', 'kind' => 'reminder', 'instruction' => '']);
        self::assertSame(422, $status);
        self::assertSame([], $this->ai->calls);
    }
}
