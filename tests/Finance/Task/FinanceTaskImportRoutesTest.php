<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Identity\Contract\UserRepository;

/** `POST /import` through the real app with the real CSV and Excel readers (T2.8): a preview by default, a real run, one audit row. */
final class FinanceTaskImportRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/task';

    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_TASK_ENABLED' => 'true']);
        $container = $this->app->getContainer();
        $directory = $container->get(FinanceTaskMemberDirectory::class);
        self::assertInstanceOf(InMemoryFinanceTaskMemberDirectory::class, $directory);
        $directory->add(1, 'Dev User', 'dev@example.test');
        $directory->add(9, 'Una User', 'una@example.test');
        $this->as([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_SETUP_MANAGE, Module::PERMISSION_TASK_TASKS_READ_ALL]);
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): void
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $request = TestAppFactory::request($method, self::BASE . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    private function csv(): string
    {
        return "\xEF\xBB\xBFTask Name,Project,Preparer,Due Date,Mark as Urgent\r\n"
            . "Bank rec,Month End,Una User,09/10/2026,Yes\r\n"
            . "Accruals,Month End,Nobody Known,10/10/2026,No\r\n";
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function data(array $json): array
    {
        self::assertTrue($json['success'], json_encode($json, JSON_THROW_ON_ERROR));
        $data = $json['data'];
        self::assertIsArray($data);
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private function auditLog(): InMemoryFinanceAuditLog
    {
        $log = $this->app->getContainer()->get(FinanceAuditLog::class);
        self::assertInstanceOf(InMemoryFinanceAuditLog::class, $log);

        return $log;
    }

    public function test_a_csv_is_previewed_by_default_and_writes_nothing(): void
    {
        [$status, $json] = $this->call('POST', '/import', ['filename' => 'tasks.csv', 'data' => base64_encode($this->csv())]);

        self::assertSame(200, $status);
        $report = self::data($json);
        self::assertTrue($report['dry_run']);
        self::assertSame(['projects' => 1, 'projects_existing' => 0, 'tasks' => 1, 'tasks_duplicate' => 0, 'errors' => 1], $report['summary']);
        [, $tasks] = $this->call('GET', '/tasks');
        self::assertSame([], $tasks['data']);
        self::assertSame([], $this->auditLog()->events);
    }

    public function test_only_an_explicit_false_makes_it_real_and_a_real_run_loads_the_rows_and_writes_one_audit_row(): void
    {
        foreach ([['dry_run' => null], ['dry_run' => 'false'], ['dry_run' => 0]] as $extra) {
            [, $json] = $this->call('POST', '/import', ['filename' => 'tasks.csv', 'data' => base64_encode($this->csv())] + $extra);
            self::assertTrue(self::data($json)['dry_run'], 'anything but a JSON false is a preview');
        }

        [$status, $json] = $this->call('POST', '/import', ['filename' => 'tasks.csv', 'data' => base64_encode($this->csv()), 'dry_run' => false]);

        self::assertSame(200, $status);
        $report = self::data($json);
        self::assertFalse($report['dry_run']);
        self::assertSame(['projects' => 1, 'projects_existing' => 0, 'tasks' => 1, 'tasks_duplicate' => 0, 'errors' => 1], $report['summary']);
        [, $tasks] = $this->call('GET', '/tasks');
        self::assertSame(['Bank rec'], array_column((array) $tasks['data'], 'name'));
        [, $projects] = $this->call('GET', '/projects');
        self::assertSame(['Month End'], array_column((array) $projects['data'], 'name'));
        $events = $this->auditLog()->events;
        self::assertCount(1, $events);
        self::assertSame('finance.task.import', $events[0]->action);
        self::assertSame(1, $events[0]->actorUserId);
        self::assertSame('tasks.csv', $events[0]->details['filename']);

        [, $json] = $this->call('POST', '/import', ['filename' => 'tasks.csv', 'data' => base64_encode($this->csv()), 'dry_run' => false]);
        self::assertSame(0, ((array) self::data($json)['summary'])['tasks'], 'the same file again adds nothing');
        self::assertCount(1, Typed::list($this->call('GET', '/tasks')[1]['data']));
    }

    public function test_a_file_that_cannot_be_read_is_a_422_with_the_sources_words(): void
    {
        foreach ([
            [['filename' => 'x.csv', 'data' => '!!not base64!!'], 'The file could not be read'],
            [['filename' => 'x.csv', 'data' => ''], 'The file is empty'],
            [['filename' => 'x.csv'], 'The file is empty'],
            [['filename' => 'x.pdf', 'data' => base64_encode('%PDF')], 'Upload the import template as .xlsx or .csv'],
            [['filename' => 'x.csv', 'data' => base64_encode("Name,Amount\r\nA,1\r\n")], 'The file is not the import template: it needs a "Task Name" or a "Project name" column'],
        ] as [$body, $message]) {
            [$status, $json] = $this->call('POST', '/import', $body);
            self::assertSame(422, $status, $message);
            self::assertFalse($json['success']);
            self::assertSame($message, ((array) $json['error'])['message']);
            self::assertSame('FINANCE_TASK_IMPORT_FAILED', ((array) $json['error'])['code']);
        }
    }

    public function test_a_body_larger_than_a_5_mb_file_can_be_is_refused_before_it_is_read(): void
    {
        [$status, $json] = $this->call('POST', '/import', ['filename' => 'x.csv', 'data' => 'AAAA'], ['Content-Length' => (string) (8 * 1024 * 1024)]);

        self::assertSame(422, $status);
        self::assertSame('The file is larger than 5 MB', ((array) $json['error'])['message']);
    }

    public function test_the_import_needs_the_create_permission_and_projects_need_setup(): void
    {
        $this->as([Module::PERMISSION_TASK_TASKS_READ]);
        [$status] = $this->call('POST', '/import', ['filename' => 'tasks.csv', 'data' => base64_encode($this->csv())]);
        self::assertSame(403, $status);

        $this->as([Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE]);
        [$status, $json] = $this->call('POST', '/import', ['filename' => 'tasks.csv', 'data' => base64_encode($this->csv()), 'dry_run' => false]);
        self::assertSame(200, $status);
        $report = self::data($json);
        self::assertSame(['error', 'error'], array_column((array) $report['tasks'], 'action'));
        self::assertStringContainsString('permission to create projects', Typed::string(Typed::list(Typed::row(Typed::list($report['tasks'])[0])['errors'])[0]));
    }

    public function test_an_excel_workbook_is_read_end_to_end_when_the_zip_extension_is_loaded(): void
    {
        if (!extension_loaded('zip') || !extension_loaded('xmlreader')) {
            self::markTestSkipped('The zip extension is not loaded (enable extension=zip, as Phase 2 asks of the local php.ini).');
        }
        $path = tempnam(sys_get_temp_dir(), 'tt-test-');
        self::assertIsString($path);
        try {
            $writer = new Writer();
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName('Task import template');
            $writer->addRow(Row::fromValues(['Task Name', 'Project', 'Preparer', 'Due Date', 'Progress %']));
            $writer->addRow(Row::fromValues(['Bank rec', 'Month End', 'Una User', '09/10/2026', 45]));
            $writer->close();
            $bytes = file_get_contents($path);
            self::assertIsString($bytes);
        } finally {
            @unlink($path);
        }

        [$status, $json] = $this->call('POST', '/import', ['filename' => 'close.xlsx', 'data' => base64_encode($bytes), 'dry_run' => false]);

        self::assertSame(200, $status);
        self::assertSame(1, ((array) self::data($json)['summary'])['tasks']);
        [, $tasks] = $this->call('GET', '/tasks');
        self::assertSame([45], array_column((array) $tasks['data'], 'progress'));
    }
}
