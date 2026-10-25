<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\AzureSqlTaskSetupStore;
use TriCab\Api\Finance\Task\AzureSqlTaskStore;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * Unread, Flag and Pin per person over `alter-finance-task-user-state.sql`, against a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one. The same scenarios as {@see TaskUserStateTest}.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskUserStateTest extends TestCase
{
    use TaskUserStateScenarios;

    private PDO $pdo;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $directory = new InMemoryFinanceTaskMemberDirectory();
        foreach ([11 => 'Una User', 12 => 'Max Manager', 13 => 'Rae Reader'] as $id => $name) {
            $directory->add($id, $name, 'm' . $id . '@example.test');
        }
        $this->clock = FixedClock::utc('2026-10-07 01:02:03.456789');
        $this->tasks = new TaskService(new AzureSqlTaskStore($connection), new AzureSqlTaskSetupStore($connection), $directory, new TaskTime($this->clock, 'Australia/Melbourne'));
        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE]);
        $this->reader = new TaskActor(13, 'Rae Reader', 'rae@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL]);
    }

    private function tick(): void
    {
        $this->clock->advance('+1 second');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function make(TaskActor $by, string $name, array $data = []): array
    {
        $this->tick();

        return $this->tasks->create($by, ['name' => $name] + $data);
    }

    private function cutoffAt(DateTimeImmutable $cutoff): void
    {
        $this->pdo->exec("UPDATE [finance].[task_unread_cutoff] SET [cutoff_at] = '" . $cutoff->format('Y-m-d H:i:s') . "'");
    }

    public function test_the_script_leaves_one_cutoff_row_and_a_second_run_does_not_move_it(): void
    {
        $this->cutoffAt(new DateTimeImmutable('2001-02-03 04:05:06'));
        $script = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/alter-finance-task-user-state.sql');
        self::assertIsString($script);
        foreach (preg_split('/^\\s*GO\\s*$/mi', $script) ?: [] as $batch) {
            if (trim($batch) !== '') {
                $this->pdo->exec($batch);
            }
        }

        $count = $this->pdo->query('SELECT COUNT(*) FROM [finance].[task_unread_cutoff]');
        $at = $this->pdo->query('SELECT CONVERT(VARCHAR(19), MIN([cutoff_at]), 120) FROM [finance].[task_unread_cutoff]');
        self::assertNotFalse($count);
        self::assertNotFalse($at);
        self::assertSame(1, (int) $count->fetchColumn());
        self::assertSame('2001-02-03 04:05:06', $at->fetchColumn());
    }
}
