<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Audit\AzureSqlFinanceAuditLog;
use TriCab\Api\Finance\Task\AzureSqlTaskSetupStore;
use TriCab\Api\Finance\Task\AzureSqlTaskStore;
use TriCab\Api\Finance\Task\CsvSpreadsheetReader;
use TriCab\Api\Finance\Task\FormatSpreadsheetReader;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\OpenSpoutXlsxReader;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Service\TaskImportService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * The import over the real tables on a local SQL Server ({@see LocalFinanceSqlServer}); skipped without one: a
 * preview changes no table, a real run adds what the report said, and the same file again adds nothing.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskImportTest extends TestCase
{
    private PDO $pdo;

    private TaskImportService $import;

    private TaskService $tasks;

    private TaskActor $admin;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $store = new AzureSqlTaskStore($connection);
        $setupStore = new AzureSqlTaskSetupStore($connection);
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Una User', 'una@example.test');
        $directory->add(12, 'Mo Manager', 'mo@example.test');
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:00:00.123456'), 'Australia/Melbourne');
        $tasks = new TaskService($store, $setupStore, $directory, $time);
        $this->tasks = $tasks;
        $setup = new TaskSetupService($setupStore, $directory, $time);
        $this->import = new TaskImportService(
            new FormatSpreadsheetReader(new CsvSpreadsheetReader(), new OpenSpoutXlsxReader()),
            $setup, $tasks, $setupStore, $store, $directory, new AzureSqlFinanceAuditLog($connection), $time,
        );
        $this->admin = new TaskActor(12, 'Mo Manager', 'mo@example.test', [
            Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_SETUP_MANAGE, Module::PERMISSION_TASK_TASKS_READ_ALL,
            Module::PERMISSION_TASK_TASKS_UPDATE,
        ]);
        $setup->createEntity(['name' => 'Société Générale', 'code' => 'SG']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];
        foreach (['task_projects', 'task_tasks', 'task_history'] as $table) {
            $statement = $this->pdo->query('SELECT COUNT(*) FROM [finance].[' . $table . ']');
            self::assertInstanceOf(PDOStatement::class, $statement);
            $out[$table] = (int) $statement->fetchColumn();
        }
        $statement = $this->pdo->query("SELECT COUNT(*) FROM [finance].[audit_events] WHERE [action] = N'finance.task.import'");
        self::assertInstanceOf(PDOStatement::class, $statement);
        $out['audit'] = (int) $statement->fetchColumn();

        return $out;
    }

    private function file(): string
    {
        return "\xEF\xBB\xBFEntity,Task Name,Project,Preparer,Reviewer,Due Date,Progress %,Notes\r\n"
            . "SG,Rapprochement bancaire — ✓,Clôture octobre,Una User,Mo Manager,09/10/2026,45,\"line one\nline two\"\r\n"
            . "SG,Accruals,Clôture octobre,una@example.test,,10-Oct-26,,\r\n"
            . "SG,Bad preparer,Clôture octobre,Nobody,,,,\r\n";
    }

    public function test_a_preview_changes_no_table_a_real_run_adds_what_it_said_and_a_second_run_adds_nothing(): void
    {
        $before = $this->counts();

        $preview = $this->import->run($this->admin, 'close.csv', $this->file(), true);
        self::assertSame($before, $this->counts(), 'a dry run writes nothing');
        self::assertSame(['projects' => 1, 'projects_existing' => 0, 'tasks' => 2, 'tasks_duplicate' => 0, 'errors' => 1, 'tasks_updated' => 0, 'tasks_unchanged' => 0], $preview['summary']);

        $real = $this->import->run($this->admin, 'close.csv', $this->file(), false);

        self::assertSame(['projects' => 1, 'projects_existing' => 0, 'tasks' => 2, 'tasks_duplicate' => 0, 'errors' => 1, 'tasks_updated' => 0, 'tasks_unchanged' => 0], $real['summary']);
        $after = $this->counts();
        self::assertSame(['task_projects' => 1, 'task_tasks' => 2, 'task_history' => 2, 'audit' => 1], $after);

        $again = $this->import->run($this->admin, 'close.csv', $this->file(), false);
        self::assertSame(0, ((array) $again['summary'])['tasks']);
        self::assertSame(2, ((array) $again['summary'])['tasks_duplicate']);
        $second = $this->counts();
        self::assertSame($after['task_tasks'], $second['task_tasks']);
        self::assertSame($after['task_projects'], $second['task_projects']);
        self::assertSame(2, $second['audit'], 'each real run is audited, even one that adds nothing');
    }

    public function test_unicode_multiline_notes_and_dates_survive_the_round_trip(): void
    {
        $this->import->run($this->admin, 'close.csv', $this->file(), false);

        $rows = $this->pdo->query('SELECT [name], [notes], [due_date], [progress], [preparer_user_id], [reviewer_user_id] FROM [finance].[task_tasks] ORDER BY [name]');
        self::assertInstanceOf(PDOStatement::class, $rows);
        $tasks = array_map(Typed::row(...), Typed::list($rows->fetchAll(PDO::FETCH_ASSOC)));
        self::assertSame('Accruals', $tasks[0]['name']);
        self::assertSame('2026-10-10', $tasks[0]['due_date']);
        self::assertSame(11, Typed::int($tasks[0]['preparer_user_id']));
        self::assertSame('Rapprochement bancaire — ✓', $tasks[1]['name']);
        self::assertSame("line one\nline two", str_replace("\r\n", "\n", Typed::string($tasks[1]['notes'])));
        self::assertSame('2026-10-09', $tasks[1]['due_date']);
        self::assertSame(45, Typed::int($tasks[1]['progress']));
        $audit = $this->pdo->query("SELECT [details_json], [actor_user_id], [application_key] FROM [finance].[audit_events] WHERE [action] = N'finance.task.import'");
        self::assertInstanceOf(PDOStatement::class, $audit);
        $row = Typed::row($audit->fetch(PDO::FETCH_ASSOC));
        self::assertSame('TASK', $row['application_key']);
        self::assertSame(12, Typed::int($row['actor_user_id']));
        self::assertStringContainsString('close.csv', Typed::string($row['details_json']));
        self::assertStringNotContainsString('Rapprochement', Typed::string($row['details_json']));
    }

    /** The register as the web's Export CSV writes it (16 Oct 2026): every field of the form, then the Task ID. */
    private function exported(string $project = ''): string
    {
        $names = ['11' => 'Una User', '12' => 'Mo Manager'];
        $person = static fn (mixed $id): string => is_string($id) ? ($names[$id] ?? '') : '';
        $field = static function (mixed $v): string {
            $text = is_scalar($v) ? (string) $v : '';

            return preg_match('/[",\n]/', $text) === 1 ? '"' . str_replace('"', '""', $text) . '"' : $text;
        };
        $lines = ["\xEF\xBB\xBFEntity,Task Name,Project,Group,Description,Requirements,Notes,Finance Statement Area,Type,Status,Priority,Preparer,Reviewer,Progress %,Due Date,Due Date Requested,Mark as Urgent,Task ID"];
        foreach ($this->tasks->list($this->admin) as $t) {
            $lines[] = implode(',', array_map($field, [
                'Société Générale', $t['name'], $project !== '' ? $project : $t['project_name'], '', $t['description'], $t['requirements'], $t['notes'],
                $t['finance_statement_area'], $t['category'], $t['display_status'], $t['priority'] ?? '',
                $person($t['preparer_id']), $person($t['reviewer_id']), $t['progress'], $t['due_date'],
                $t['due_date_requested'] ? 'Yes' : 'No', $t['urgent'] ? 'Yes' : 'No', $t['id'],
            ]));
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    public function test_an_exported_file_loaded_back_changes_nothing_and_a_renamed_project_moves_the_tasks_in_place(): void
    {
        $this->import->run($this->admin, 'close.csv', $this->file(), false);
        $before = $this->counts();

        $same = $this->import->run($this->admin, 'tasks.csv', $this->exported(), false);
        self::assertSame(2, ((array) $same['summary'])['tasks_unchanged'], json_encode($same, JSON_THROW_ON_ERROR));
        self::assertSame(0, ((array) $same['summary'])['tasks_updated']);

        $renamed = $this->import->run($this->admin, 'tasks.csv', $this->exported('Clôture octobre FY27'), false);
        self::assertSame(2, ((array) $renamed['summary'])['tasks_updated'], json_encode($renamed, JSON_THROW_ON_ERROR));
        self::assertSame(0, ((array) $renamed['summary'])['tasks'], 'no task is added');
        $after = $this->counts();
        self::assertSame($before['task_tasks'], $after['task_tasks']);
        self::assertSame($before['task_projects'] + 1, $after['task_projects']);
        self::assertSame(['Clôture octobre FY27', 'Clôture octobre FY27'], array_column($this->tasks->list($this->admin), 'project_name'));
    }
}
