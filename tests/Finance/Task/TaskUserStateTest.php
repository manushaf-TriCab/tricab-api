<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** Unread, Flag and Pin per person, on the in-memory twin; {@see AzureSqlTaskUserStateTest} runs the same on SQL. */
final class TaskUserStateTest extends TestCase
{
    use TaskUserStateScenarios;

    private InMemoryTaskStore $store;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $directory = new InMemoryFinanceTaskMemberDirectory();
        foreach ([11 => 'Una User', 12 => 'Max Manager', 13 => 'Rae Reader'] as $id => $name) {
            $directory->add($id, $name, strtolower(explode(' ', $name)[0]) . '@example.test', $id === 11 ? TaskMember::ROLE_USER : TaskMember::ROLE_MANAGER);
        }
        $this->store = new InMemoryTaskStore();
        $this->clock = FixedClock::utc('2026-10-07 01:00:00.123456');
        $this->tasks = new TaskService($this->store, $this->store, $directory, new TaskTime($this->clock, 'Australia/Melbourne'));
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
        $this->store->setUnreadCutoff($cutoff);
    }
}
