<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskMailLinks;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskMailLinks;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The task mail routes through the real app with the default (mail off) transport: a send leaves a
 * `suppressed` ledger row and a link row and makes no network call (T2.6).
 */
final class FinanceTaskMailRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/task';

    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true', 'FINANCE_TASK_MAIL_FROM' => 'Finance@Example.test']);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'finance@example.test', 'Finance Team', '', true, ['TASK' => ['read' => false, 'send' => true]]),
        ]));
        $directory = $container->get(FinanceTaskMemberDirectory::class);
        self::assertInstanceOf(InMemoryFinanceTaskMemberDirectory::class, $directory);
        $directory->add(1, 'Dev User', 'dev@example.test');
        $directory->add(9, 'Una User', 'una@example.test');
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, [
            'account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS, Module::PERMISSION_TASK_TASKS_READ,
            Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_MAIL_SEND,
        ], ['EMPLOYEE']);
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

    private function task(): string
    {
        [$status, $json] = $this->call('POST', '/tasks', ['name' => 'Bank rec', 'preparer_id' => 1]);
        self::assertSame(201, $status);
        $data = self::data($json);
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    public function test_the_mailboxes_route_lists_the_granted_mailbox_and_the_default(): void
    {
        [$status, $json] = $this->call('GET', '/send-mailboxes');

        self::assertSame(200, $status);
        self::assertSame(['mailboxes' => [['email' => 'finance@example.test', 'label' => 'Finance Team']], 'default' => 'finance@example.test'], self::data($json));
    }

    public function test_with_mail_off_a_send_leaves_a_suppressed_ledger_row_and_a_link_and_says_so_per_email(): void
    {
        $id = $this->task();

        [$status, $json] = $this->call('POST', '/mail/send', [
            'kind' => 'reminder',
            'emails' => [['task_id' => $id, 'to' => 'una@example.test', 'subject' => 'Please', 'body' => 'Hello']],
        ]);

        self::assertSame(200, $status);
        self::assertSame(['sent' => 0, 'results' => [['to' => 'una@example.test', 'ok' => false, 'error' => 'Mail is switched off', 'suppressed' => true]]], self::data($json));
        $container = $this->app->getContainer();
        $ledger = $container->get(FinanceMailLedger::class);
        self::assertInstanceOf(InMemoryFinanceMailLedger::class, $ledger);
        self::assertCount(1, $ledger->all());
        self::assertSame('suppressed', $ledger->all()[0]->status);
        self::assertSame('TASK', $ledger->all()[0]->application);
        $links = $container->get(TaskMailLinks::class);
        self::assertInstanceOf(InMemoryTaskMailLinks::class, $links);
        self::assertCount(1, $links->all());

        [$status, $json] = $this->call('GET', '/tasks/' . $id . '/mail');
        self::assertSame(200, $status);
        $history = self::data($json);
        self::assertIsArray($history);
        self::assertSame(['Please'], array_column($history, 'subject'));
        self::assertSame(['suppressed'], array_column($history, 'status'));
    }

    public function test_more_than_twenty_emails_and_a_stray_address_are_refused(): void
    {
        $id = $this->task();
        $one = ['task_id' => $id, 'to' => 'una@example.test', 'subject' => 's', 'body' => 'b'];

        [$status, $json] = $this->call('POST', '/mail/send', ['kind' => 'task_email', 'emails' => array_fill(0, 21, $one)]);
        self::assertSame(422, $status);
        self::assertSame(['code' => 'FINANCE_TASK_INPUT_INVALID', 'message' => 'Up to 20 emails at once'], self::error($json));

        [$status, $json] = $this->call('POST', '/mail/send', ['kind' => 'task_email', 'emails' => [$one, ['to' => 'una@example.test, evil@example.org, worse@example.org'] + $one]]);
        self::assertSame(422, $status);
        self::assertSame('These addresses are not Tri-Task members or summary recipients: evil@example.org, worse@example.org', self::error($json)['message']);

        $ledger = $this->app->getContainer()->get(FinanceMailLedger::class);
        self::assertInstanceOf(InMemoryFinanceMailLedger::class, $ledger);
        self::assertSame([], $ledger->all());
    }

    public function test_the_routes_need_the_mail_permission_and_the_task_mail_history_the_read_permission(): void
    {
        $id = $this->task();
        $container = $this->app->getContainer();
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_TASK_TASKS_READ], ['EMPLOYEE']);

        [$status] = $this->call('GET', '/send-mailboxes');
        self::assertSame(403, $status);
        [$status] = $this->call('POST', '/mail/send', ['kind' => 'task_email', 'emails' => []]);
        self::assertSame(403, $status);
        [$status, $json] = $this->call('GET', '/tasks/' . $id . '/mail');
        self::assertSame(200, $status);
        self::assertSame([], self::data($json));
    }

    public function test_a_from_the_person_may_not_use_is_a_403_with_the_sources_gist(): void
    {
        $id = $this->task();

        [$status, $json] = $this->call('POST', '/mail/send', [
            'kind' => 'task_email', 'from' => 'someone@example.test',
            'emails' => [['task_id' => $id, 'to' => 'una@example.test', 'subject' => 's', 'body' => 'b']],
        ]);

        self::assertSame(403, $status);
        self::assertSame('FINANCE_TASK_FORBIDDEN', self::error($json)['code']);
        $message = self::error($json)['message'];
        self::assertIsString($message);
        self::assertStringContainsString('someone@example.test', $message);
    }
}
