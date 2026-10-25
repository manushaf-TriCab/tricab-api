<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Attachments\AzureSqlFinanceAttachmentStore;
use TriCab\Api\Finance\Task\AzureSqlTaskSetupStore;
use TriCab\Api\Finance\Task\AzureSqlTaskStore;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Ar\ThrowingAttachmentStore;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Service\TaskFileGuard;
use TriCab\Core\Finance\Task\Service\TaskFileService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * Files on tasks over the SQL stores (23 Oct 2026), on a real local SQL Server ({@see LocalFinanceSqlServer}) with the
 * platform's in-memory blob store; skipped without one. The count in one grouped query, the files' rows and bytes
 * for a task, and what a permanent delete of a task, or of a project with its binned tasks, takes with it.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskFilesTest extends TestCase
{
    private const CONTAINER = 'finance-test';

    private PDO $pdo;

    private InMemoryProductBlobStore $blobs;

    private ThrowingAttachmentStore $attachments;

    private TaskFileService $files;

    private TaskService $tasks;

    private TaskSetupService $setup;

    private TaskActor $max;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        InMemoryProductBlobStore::resetSharedForTests();
        $this->blobs = new InMemoryProductBlobStore();
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $store = new AzureSqlTaskStore($connection);
        $setupStore = new AzureSqlTaskSetupStore($connection);
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(12, 'Max Manager', 'max@example.test');
        $this->attachments = new ThrowingAttachmentStore(new AzureSqlFinanceAttachmentStore($connection, $this->blobs, self::CONTAINER));
        $this->attachments->failDeletes = false;
        $this->files = new TaskFileService(new FinanceAttachmentService($this->attachments), $this->attachments, $store, new TaskFileGuard($store), $directory);
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:02:03.456789'), 'Australia/Melbourne');
        $this->tasks = new TaskService($store, $setupStore, $directory, $time, files: $this->files);
        $this->setup = new TaskSetupService($setupStore, $directory, $time, $this->files);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [
            Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN,
            Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE, Module::PERMISSION_TASK_SETUP_MANAGE,
        ]);
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private function task(string $name, ?string $projectId = null): string
    {
        $made = $this->tasks->create($this->max, ['name' => $name, 'preparer_id' => 12] + ($projectId === null ? [] : ['project_id' => $projectId]));
        self::assertIsString($made['id']);

        return $made['id'];
    }

    public function test_the_count_is_one_grouped_read_and_the_rows_and_bytes_are_the_tasks(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');
        $file = $this->files->add($this->max, $a, 'Statement.pdf', 'application/pdf', 'pdf bytes');
        $this->files->add($this->max, $a, 'Statement.pdf', 'application/pdf', 'again');
        $this->files->add($this->max, $b, 'b.xlsx', 'application/vnd.ms-excel', 'b');

        self::assertEqualsCanonicalizing([$a => 2, $b => 1], $this->files->counts());
        self::assertSame(2, $this->files->counts()[$a]);
        self::assertSame(2, $this->tasks->get($this->max, $a)['file_count']);
        self::assertSame(3, $this->scalar("SELECT COUNT(*) FROM [finance].[attachments] WHERE [application_key] = N'TASK' AND [record_type] = N'task'"));
        self::assertSame(3, $this->scalar("SELECT COUNT(*) FROM [finance].[attachments] WHERE [blob_path] LIKE N'task/____/__/%'"));
        self::assertSame('pdf bytes', $this->files->download($this->max, $a, is_string($file['id']) ? $file['id'] : '')->bytes);
        self::assertSame(['Statement.pdf', 'Statement.pdf'], array_column($this->files->list($this->max, $a), 'name'));
    }

    public function test_deleting_a_task_for_good_removes_its_bytes_and_rows(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');
        $this->files->add($this->max, $a, 'a.pdf', 'application/pdf', 'a');
        $this->files->add($this->max, $b, 'b.pdf', 'application/pdf', 'b');

        $this->tasks->delete($this->max, $a);
        self::assertSame(1, $this->tasks->get($this->max, $a)['file_count'], 'the Bin keeps files');
        self::assertSame(['deleted' => true, 'permanent' => true], $this->tasks->delete($this->max, $a, true));

        self::assertSame([$b => 1], $this->files->counts());
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[attachments]'));
        $this->expectException(TaskNotFound::class);
        $this->files->list($this->max, $a);
    }

    public function test_a_project_deleted_for_good_removes_the_files_of_its_binned_tasks(): void
    {
        $project = $this->setup->createProject($this->max, ['name' => 'Close']);
        self::assertIsString($project['id']);
        $in1 = $this->task('In 1', $project['id']);
        $in2 = $this->task('In 2', $project['id']);
        $out = $this->task('Outside');
        foreach ([$in1, $in2, $out] as $id) {
            $this->files->add($this->max, $id, 'f.pdf', 'application/pdf', $id);
        }
        $before = $this->scalar('SELECT COUNT(*) FROM [finance].[attachments]');
        self::assertSame(3, $before);

        $this->setup->deleteProject($this->max, $project['id']);
        self::assertSame($before, $this->scalar('SELECT COUNT(*) FROM [finance].[attachments]'));
        self::assertSame(['deleted' => true, 'permanent' => true], $this->setup->deleteProject($this->max, $project['id'], true));

        self::assertSame([$out => 1], $this->files->counts());
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[attachments]'));
    }

    public function test_when_the_blob_service_is_down_the_delete_still_happens_and_says_files_were_left(): void
    {
        $a = $this->task('A');
        $this->files->add($this->max, $a, 'a.pdf', 'application/pdf', 'a');
        $this->tasks->delete($this->max, $a);
        $this->attachments->failDeletes = true;

        $result = $this->tasks->delete($this->max, $a, true);

        self::assertSame(['deleted' => true, 'permanent' => true, 'files_orphaned' => true], $result);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[task_tasks] WHERE [id] = '" . $a . "'"));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[attachments]'), 'the row stays so a later delete can finish');
    }
}
