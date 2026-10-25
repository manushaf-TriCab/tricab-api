<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskAiFailed;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;
use TriCab\Core\Finance\Task\Infrastructure\HeuristicTaskDrafter;
use TriCab\Core\Finance\Task\Infrastructure\UnavailableTaskDrafter;
use TriCab\Core\Finance\Task\Service\TaskDraftService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** "Create with AI": the model's proposal is checked against our lists. No model is called; the drafter is a fake. */
final class TaskDraftServiceTest extends TestCase
{
    private HeuristicTaskDrafter $drafter;

    private TaskDraftService $service;

    private string $entityId;

    private string $projectId;

    private string $groupId;

    private TaskActor $manager;

    private TaskActor $user;

    protected function setUp(): void
    {
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Mia Chen', 'mia@example.test');
        $directory->add(12, 'Lin Park', 'lin@example.test');
        $store = new InMemoryTaskStore();
        // 2026-10-07 is a Wednesday in Melbourne.
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:00:00'), 'Australia/Melbourne');
        $setup = new TaskSetupService($store, $directory, $time);
        $this->entityId = self::idOf($setup->createEntity(['name' => 'TriCab Australia', 'code' => 'AU']));
        $this->projectId = self::idOf($setup->createProject(new TaskActor(12, 'Lin', 'lin@example.test', []), ['name' => 'Year-end close']));
        $this->groupId = self::idOf($setup->createProjectGroup(['name' => 'Finance']));
        $this->drafter = new HeuristicTaskDrafter();
        $this->service = new TaskDraftService($this->drafter, $store, $directory, $time);
        $this->manager = new TaskActor(12, 'Lin Park', 'lin@example.test', [Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_SETUP_MANAGE]);
        $this->user = new TaskActor(11, 'Mia Chen', 'mia@example.test', [Module::PERMISSION_TASK_TASKS_CREATE]);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function draft(array $raw, ?TaskActor $actor = null): array
    {
        $this->drafter->next = $raw;

        return $this->service->draft($actor ?? $this->manager, ['text' => 'Mia to do the QLD car insurance claim by Friday, Lin reviews']);
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function firstTask(array $draft): array
    {
        $tasks = $draft['tasks'];
        self::assertIsArray($tasks);
        $first = $tasks[0];
        self::assertIsArray($first);
        $typed = [];
        foreach ($first as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /** @param array<string, mixed> $draft */
    private static function warning(array $draft, int $index = 0): string
    {
        $warnings = $draft['warnings'];
        self::assertIsArray($warnings);
        self::assertIsString($warnings[$index]);

        return $warnings[$index];
    }

    /** @param array<string, mixed> $row */
    private static function idOf(array $row): string
    {
        self::assertIsString($row['id']);

        return $row['id'];
    }

    public function test_the_model_is_given_the_lists_today_and_the_vocabularies(): void
    {
        $this->draft(['tasks' => []]);

        $request = $this->drafter->lastRequest;
        self::assertNotNull($request);
        self::assertSame('2026-10-07', $request->today);
        self::assertSame('Wednesday', $request->weekday);
        self::assertSame('Australia/Melbourne', $request->timezone);
        self::assertSame(['12', '11'], array_column($request->members, 'id'));
        self::assertSame([$this->entityId], array_column($request->entities, 'id'));
        self::assertSame([$this->projectId], array_column($request->projects, 'id'));
        self::assertSame([$this->groupId], array_column($request->projectGroups, 'id'));
        self::assertSame(['critical', 'high', 'medium', 'low'], $request->priorities);
        self::assertSame(['routine', 'ad_hoc'], $request->categories);
        self::assertSame(['active', 'on_hold'], $request->statuses);
        self::assertTrue($request->mayCreateProject);
    }

    public function test_a_good_proposal_comes_back_clean_with_the_phrase_resolved_to_a_date(): void
    {
        $draft = $this->draft(['tasks' => [[
            'name' => ' Lodge QLD car insurance claim ', 'description' => 'Claim for the Brisbane van.', 'notes' => '',
            'project_id' => $this->projectId, 'entity_id' => $this->entityId, 'preparer_id' => '11', 'reviewer_id' => 12,
            'priority' => 'high', 'category' => 'ad_hoc', 'status' => 'on_hold', 'due_phrase' => 'Friday', 'due_date' => '',
        ]], 'injection_suspected' => false]);

        $task = $this->firstTask($draft);
        self::assertSame('Lodge QLD car insurance claim', $task['name']);
        self::assertSame($this->projectId, $task['project_id']);
        self::assertSame($this->entityId, $task['entity_id']);
        self::assertSame('11', $task['preparer_id']);
        self::assertSame('12', $task['reviewer_id']);
        self::assertSame('high', $task['priority']);
        self::assertSame('ad_hoc', $task['category']);
        self::assertSame('on_hold', $task['status']);
        self::assertSame('2026-10-09', $task['due_date']);
        self::assertSame([], $draft['warnings']);
        self::assertFalse($draft['injection_suspected']);
        self::assertSame('heuristic-local', $draft['model']);
    }

    public function test_ids_that_are_not_in_the_lists_are_dropped_and_values_outside_the_vocabularies_are_blanked(): void
    {
        $draft = $this->draft(['tasks' => [[
            'name' => 'Do a thing', 'project_id' => 'deadbeef', 'entity_id' => 'nope', 'preparer_id' => '999', 'reviewer_id' => "11'; DROP TABLE x",
            'priority' => 'urgent!!', 'category' => 'weekly', 'status' => 'completed',
        ]]]);

        $task = $this->firstTask($draft);
        self::assertNull($task['project_id']);
        self::assertNull($task['entity_id']);
        self::assertNull($task['preparer_id']);
        self::assertNull($task['reviewer_id']);
        self::assertNull($task['priority']);
        self::assertSame('routine', $task['category']);
        self::assertSame('active', $task['status'], 'a draft can never start a task as Done');
    }

    public function test_the_phrase_wins_over_the_models_own_date_which_is_kept_only_when_real_and_not_past(): void
    {
        $draft = $this->draft(['tasks' => [
            ['name' => 'A', 'due_phrase' => 'end of month', 'due_date' => '2026-10-15'],
            ['name' => 'B', 'due_phrase' => 'when it is done', 'due_date' => '2026-11-02'],
            ['name' => 'C', 'due_date' => '2025-01-01'],
            ['name' => 'D', 'due_date' => '2026-02-31'],
            ['name' => 'E', 'due_date' => 'next friday'],
            ['name' => 'F'],
        ]]);

        self::assertSame(['2026-10-31', '2026-11-02', null, null, null, null], array_column((array) $draft['tasks'], 'due_date'));
    }

    public function test_unmatched_names_become_a_warning_on_the_task(): void
    {
        $draft = $this->draft(['tasks' => [['name' => 'Chase invoice', 'preparer_id' => '', 'unmatched_people' => ['Bob']]]]);

        self::assertSame(['Could not match "Bob" to a Tri-Task member.'], $this->firstTask($draft)['warnings']);
    }

    public function test_a_manager_gets_the_new_project_with_a_resolved_target_date_and_only_a_real_group(): void
    {
        $draft = $this->draft([
            'project' => ['name' => 'FY27 budget', 'description' => 'Build the budget.', 'target_phrase' => 'end of month', 'group_id' => $this->groupId],
            'tasks' => [['name' => 'Collect forecasts', 'in_new_project' => true, 'project_id' => $this->projectId]],
        ]);

        self::assertSame(['name' => 'FY27 budget', 'description' => 'Build the budget.', 'target_date' => '2026-10-31', 'group_id' => $this->groupId], $draft['project']);
        $task = $this->firstTask($draft);
        self::assertTrue($task['in_new_project']);
        self::assertNull($task['project_id'], 'a task in the new project has no existing one');

        $draft = $this->draft(['project' => ['name' => 'Other', 'group_id' => 'made-up'], 'tasks' => []]);
        self::assertIsArray($draft['project']);
        self::assertNull($draft['project']['group_id']);
    }

    public function test_someone_who_may_not_create_projects_is_offered_none(): void
    {
        $draft = $this->draft([
            'project' => ['name' => 'FY27 budget'],
            'tasks' => [['name' => 'Collect forecasts', 'in_new_project' => true]],
        ], $this->user);

        self::assertNull($draft['project']);
        $task = $this->firstTask($draft);
        self::assertFalse($task['in_new_project']);
        self::assertNull($task['project_id']);
        self::assertCount(1, (array) $draft['warnings']);
        self::assertStringContainsString('cannot create projects', self::warning($draft));
        $request = $this->drafter->lastRequest;
        self::assertNotNull($request);
        self::assertFalse($request->mayCreateProject);
    }

    public function test_a_new_project_with_the_name_of_a_live_one_files_the_tasks_in_the_live_one(): void
    {
        $draft = $this->draft([
            'project' => ['name' => 'year-end CLOSE'],
            'tasks' => [['name' => 'Post accruals', 'in_new_project' => true]],
        ]);

        self::assertNull($draft['project']);
        $task = $this->firstTask($draft);
        self::assertSame($this->projectId, $task['project_id']);
        self::assertFalse($task['in_new_project']);
        self::assertStringContainsString('already exists', self::warning($draft));
    }

    public function test_rows_without_a_name_are_skipped_and_the_list_is_capped(): void
    {
        $rows = [['name' => '  '], 'not a row', ['title' => 'From a title']];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = ['name' => 'Task ' . $i];
        }
        $draft = $this->draft(['tasks' => $rows]);

        self::assertCount(TaskDraftService::MAX_TASKS, (array) $draft['tasks']);
        self::assertSame('From a title', $this->firstTask($draft)['name']);
        self::assertStringContainsString('first 30', self::warning($draft));
    }

    public function test_nothing_found_says_so_and_the_injection_flag_is_passed_on(): void
    {
        $draft = $this->draft(['tasks' => [], 'injection_suspected' => true]);

        self::assertSame([], $draft['tasks']);
        self::assertTrue($draft['injection_suspected']);
        self::assertStringContainsString('No tasks or project', self::warning($draft));
    }

    public function test_text_is_required_and_bounded_and_never_reaches_the_model_otherwise(): void
    {
        foreach ([[], ['text' => '   '], ['text' => 5]] as $body) {
            try {
                $this->service->draft($this->manager, $body);
                self::fail('expected a TaskInvalid');
            } catch (TaskInvalid $e) {
                self::assertSame('Write or dictate a summary first', $e->getMessage());
            }
        }
        $this->expectException(TaskInvalid::class);
        try {
            $this->service->draft($this->manager, ['text' => str_repeat('a', TaskDraftService::MAX_TEXT_CHARS + 1)]);
        } finally {
            self::assertNull($this->drafter->lastRequest);
        }
    }

    public function test_a_drafter_that_is_not_set_up_or_fails_is_a_clean_failure_not_an_empty_draft(): void
    {
        $service = new TaskDraftService(new UnavailableTaskDrafter(), new InMemoryTaskStore(), new InMemoryFinanceTaskMemberDirectory(), new TaskTime(FixedClock::utc('2026-10-07 01:00:00')));
        try {
            $service->draft($this->manager, ['text' => 'Do something']);
            self::fail('expected a TaskUnavailable');
        } catch (TaskUnavailable $e) {
            self::assertSame(503, $e->httpStatus());
        }

        $failing = new class() implements \TriCab\Core\Finance\Task\Contract\TaskDrafter {
            public function draft(\TriCab\Core\Finance\Task\Domain\TaskDraftRequest $request): array
            {
                throw new TaskAiFailed('The AI draft failed: the model could not be reached or took too long');
            }

            public function modelName(): string
            {
                return '';
            }
        };
        $service = new TaskDraftService($failing, new InMemoryTaskStore(), new InMemoryFinanceTaskMemberDirectory(), new TaskTime(FixedClock::utc('2026-10-07 01:00:00')));
        $this->expectException(TaskAiFailed::class);
        $service->draft($this->manager, ['text' => 'Do something']);
    }

    public function test_the_local_stand_in_makes_a_task_per_line_with_the_named_member_and_by_phrase(): void
    {
        $this->drafter->next = null;
        $draft = $this->service->draft($this->manager, ['text' => "- Mia to lodge the claim by Friday.\n\n2) Order stationery"]);

        $tasks = (array) $draft['tasks'];
        self::assertCount(2, $tasks);
        $first = (array) $tasks[0];
        self::assertSame('Mia to lodge the claim by Friday.', $first['name']);
        self::assertSame('11', $first['preparer_id']);
        self::assertSame('2026-10-09', $first['due_date']);
        self::assertNull(((array) $tasks[1])['preparer_id']);
    }
}
