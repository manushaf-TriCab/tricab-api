<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemorySpreadsheetReader;
use TriCab\Api\Finance\Task\InMemoryTaskBoardStore;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskImportFailed;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Service\TaskBoardService;
use TriCab\Core\Finance\Task\Service\TaskImportService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * The importer's rules, ported from `test_spreadsheet_import.py`, on a reader that hands back the grid a
 * test built: nothing here needs a file format (the readers have their own tests).
 */
final class TaskImportServiceTest extends TestCase
{
    private InMemoryTaskStore $store;

    private InMemorySpreadsheetReader $reader;

    private InMemoryFinanceAuditLog $audit;

    private TaskImportService $import;

    private TaskService $tasks;

    private TaskSetupService $setup;

    private TaskBoardService $groups;

    private TaskActor $admin;

    private TaskActor $member;

    protected function setUp(): void
    {
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Una User', 'una@example.test');
        $directory->add(12, 'Mo Manager', 'mo@example.test');
        $directory->add(13, 'Ada Admin', 'ada@example.test');
        $directory->add(14, 'Ada Admin', 'ada.two@example.test');   // two people with one name
        $this->store = new InMemoryTaskStore();
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:00:00'), 'Australia/Melbourne');
        $board = new InMemoryTaskBoardStore();
        $this->tasks = new TaskService($this->store, $this->store, $directory, $time, $board);
        $this->setup = new TaskSetupService($this->store, $directory, $time);
        $this->groups = new TaskBoardService($board, $this->store, $this->store, $this->tasks, $this->setup, $directory, $time);
        $this->reader = new InMemorySpreadsheetReader();
        $this->audit = new InMemoryFinanceAuditLog();
        $this->import = new TaskImportService($this->reader, $this->setup, $this->tasks, $this->store, $this->store, $directory, $this->audit, $time, $this->groups);

        $this->admin = new TaskActor(13, 'Ada Admin', 'ada@example.test', [
            Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_SETUP_MANAGE,
            Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE,
        ]);
        $this->member = new TaskActor(11, 'Una User', 'una@example.test', [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE]);
        $this->setup->createEntity(['name' => 'Example Trading', 'code' => 'ET']);
    }

    /**
     * @param list<list<mixed>> $projects
     * @param list<list<mixed>> $tasks
     */
    private function file(array $projects = [], array $tasks = []): void
    {
        $this->reader->sheets = [];
        [$p, $t] = ImportFixtures::sheets($projects, $tasks);
        $this->reader->withSheet('Project import template', $p)->withSheet('Task import template', $t);
    }

    /**
     * The register as exported, with its Task IDs: the round trip of 16 Oct 2026.
     *
     * @param list<list<mixed>> $tasks
     * @param list<string> $head
     */
    private function exported(array $tasks, array $head = ImportFixtures::EXPORT_HEAD): void
    {
        $this->reader->sheets = [];
        [, $t] = ImportFixtures::sheets(tasks: $tasks, taskHead: $head);
        $this->reader->withSheet('tasks.csv', $t);
    }

    /**
     * @param array<string, mixed> $cells
     * @param list<string> $head
     * @return list<mixed>
     */
    private static function exportRow(string $name = 'Bank rec', string $project = 'Month End', array $cells = [], array $head = ImportFixtures::EXPORT_HEAD): array
    {
        return ImportFixtures::task($name, $project, $cells, $head);
    }

    /**
     * @param array<string, mixed> $cells
     * @return list<mixed>
     */
    private static function row(string $name = 'Bank rec', string $project = 'Month End', array $cells = []): array
    {
        return ImportFixtures::task($name, $project, $cells);
    }

    /** @return array<string, mixed> */
    private function load(TaskActor $actor, bool $dry = true, string $filename = 'import.xlsx', string $bytes = 'x'): array
    {
        return $this->import->run($actor, $filename, $bytes, $dry);
    }

    /** @return list<array<string, mixed>> */
    private function lines(mixed $part): array
    {
        self::assertIsArray($part);
        $out = [];
        foreach ($part as $line) {
            self::assertIsArray($line);
            $clean = [];
            foreach ($line as $key => $value) {
                $clean[(string) $key] = $value;
            }
            $out[] = $clean;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function register(): array
    {
        return $this->tasks->list($this->admin);
    }

    // ---- the twelve ----------------------------------------------------------------------

    public function test_the_preview_writes_nothing_and_is_what_a_dry_run_reports(): void
    {
        $this->file(tasks: [self::row()]);

        $report = $this->load($this->admin);

        self::assertTrue($report['dry_run']);
        self::assertSame(['projects' => 1, 'projects_existing' => 0, 'tasks' => 1, 'tasks_duplicate' => 0, 'errors' => 0, 'tasks_updated' => 0, 'tasks_unchanged' => 0], $report['summary']);
        self::assertSame([['Month End', 'create', 'Task sheet']], array_map(static fn (array $p): array => [$p['name'], $p['action'], $p['source']], $this->lines($report['projects'])));
        self::assertSame([], $this->register());
        self::assertSame([], $this->setup->listProjects($this->admin));
        self::assertSame([], $this->audit->events, 'a preview leaves no audit row');
        self::assertSame(['filename', 'dry_run', 'projects', 'tasks', 'warnings', 'summary'], array_keys($report));
    }

    public function test_an_import_creates_projects_and_tasks_with_every_column_and_writes_history(): void
    {
        $this->file(
            projects: [['Month End', 'September close', 'On Hold', '31/10/2026']],
            tasks: [self::row(cells: [
                'Description' => 'd', 'Requirements' => 'r', 'Notes' => 'n', 'Finance Statement Area' => 'Cash', 'Category' => 'Ad-hoc',
                'Status' => 'Completed', 'Progress %' => '50%', 'Due Date' => '9 Oct 2026', 'Due Date Requested' => 'No', 'Mark as Urgent' => 'Yes',
            ])],
        );

        $report = $this->load($this->admin, false);

        self::assertSame(1, ((array) $report['summary'])['tasks'], json_encode($report, JSON_THROW_ON_ERROR));
        $project = $this->setup->listProjects($this->admin)[0];
        self::assertSame(['Month End', 'September close', 'on_hold', '2026-10-31'], [$project['name'], $project['description'], $project['status'], $project['target_date']]);
        $task = $this->register()[0];
        foreach ([
            'name' => 'Bank rec', 'project_name' => 'Month End', 'description' => 'd', 'requirements' => 'r', 'notes' => 'n',
            'finance_statement_area' => 'Cash', 'category' => 'ad_hoc', 'status' => 'completed', 'preparer_id' => '11', 'reviewer_id' => '12',
            // Done means 100%, whatever the sheet says (14 Oct 2026).
            'progress' => 100, 'due_date' => '2026-10-09', 'due_date_requested' => false, 'urgent' => true, 'created_by_id' => '13',
        ] as $field => $expected) {
            self::assertSame($expected, $task[$field], $field);
        }
        self::assertNotNull($task['entity_id']);
        self::assertNotNull($task['completed_at']);
        self::assertCount(1, $this->tasks->history($this->admin, Typed::string($task['id'])), 'the history row is written as for a task made by hand');
    }

    public function test_loading_the_same_file_twice_adds_nothing(): void
    {
        $this->file(tasks: [self::row(), self::row('Accruals')]);
        $this->load($this->admin, false);

        $again = $this->load($this->admin, false);

        self::assertSame([0, 2], [((array) $again['summary'])['tasks'], ((array) $again['summary'])['tasks_duplicate']]);
        self::assertCount(2, $this->register());
        self::assertCount(1, $this->setup->listProjects($this->admin));
        self::assertSame([], $again['projects'], 'a project the tasks name that already exists needs no line');
    }

    public function test_a_repeated_row_in_one_file_is_loaded_once(): void
    {
        $this->file(tasks: [self::row(), self::row(' bank  REC ')]);

        $report = $this->load($this->admin, false);

        self::assertSame(['created', 'duplicate'], array_column($this->lines($report['tasks']), 'action'));
        self::assertSame(['Repeats row 2'], $this->lines($report['tasks'])[1]['warnings']);
        self::assertCount(1, $this->register());
    }

    public function test_bad_rows_are_reported_and_the_good_ones_still_load(): void
    {
        $this->file(tasks: [
            self::row('Good'),
            self::row('Bad people', cells: ['Preparer' => 'Nobody Known', 'Entity' => 'Mars Pty Ltd']),
            self::row('Bad values', cells: ['Status' => 'later', 'Category' => 'weekly', 'Progress %' => 150, 'Due Date' => 'soon', 'Mark as Urgent' => 'maybe']),
            self::row('', ''),
        ]);

        $report = $this->load($this->admin, false);

        $lines = $this->lines($report['tasks']);
        self::assertSame(['created', 'error', 'error', 'error'], array_column($lines, 'action'));
        self::assertSame(['Entity "Mars Pty Ltd" is not an entity in Tri-Task', 'Preparer "Nobody Known" is not a member of Tri-Task'], $lines[1]['errors']);
        self::assertCount(5, (array) $lines[2]['errors']);
        self::assertSame([
            'Status "later" must be Active, On Hold or Done',
            'Type "weekly" must be Routine or Ad-hoc',
            'Progress % must be a number from 0 to 100, not "150"',
            'Due Date "soon" is not a date (use dd/mm/yyyy)',
            'Mark as Urgent must be Yes or No, not "maybe"',
        ], $lines[2]['errors']);
        // A blank Project is not an error since 16 Oct 2026: the task goes in General.
        self::assertSame(['Task Name is required'], $lines[3]['errors']);
        self::assertSame([3, 3], [$lines[1]['row'], ((array) $report['summary'])['errors']]);
        self::assertSame(['Good'], array_column($this->register(), 'name'));
    }

    public function test_a_project_named_only_by_rows_that_fail_is_not_created(): void
    {
        $this->file(tasks: [self::row(project: 'Orphan', cells: ['Preparer' => 'Nobody Known'])]);

        $report = $this->load($this->admin, false);

        self::assertSame([[], 0], [$report['projects'], ((array) $report['summary'])['projects']]);
        self::assertSame([], $this->setup->listProjects($this->admin));
    }

    public function test_people_match_by_name_or_email_and_entities_by_name_or_code_and_a_shared_name_is_an_error(): void
    {
        $this->file(tasks: [
            self::row(cells: ['Entity' => 'et', 'Preparer' => 'UNA@example.test', 'Reviewer' => '  mo   manager ']),
            self::row('Ambiguous', cells: ['Preparer' => 'Ada Admin']),
        ]);

        $report = $this->load($this->admin, false);

        $lines = $this->lines($report['tasks']);
        self::assertSame(['created', 'error'], array_column($lines, 'action'));
        self::assertSame(['Preparer "Ada Admin" matches more than one person'], $lines[1]['errors']);
        $task = $this->register()[0];
        self::assertSame(['11', '12'], [$task['preparer_id'], $task['reviewer_id']]);
    }

    public function test_overdue_is_imported_as_active_with_a_note(): void
    {
        $this->file(tasks: [self::row(cells: ['Status' => 'Overdue'])]);

        $report = $this->load($this->admin, false);

        self::assertSame('Overdue is worked out from the due date; imported as Active', Typed::string(Typed::list($this->lines($report['tasks'])[0]['warnings'])[0]));
        self::assertSame('active', $this->register()[0]['status']);
    }

    public function test_an_unknown_column_is_a_warning_and_an_alias_heading_is_read(): void
    {
        $this->reader->sheets = [];
        $this->reader->withSheet(null, [
            ['Entity', 'Task Name', 'Project', 'Financial Statements', 'Preparer', 'Due Date', 'Surprise'],
            ['Example Trading', 'Bank rec', 'Month End', 'Cash', 'Una User', '09/10/2026', 'x'],
        ]);

        $report = $this->load($this->admin, false, 'tasks.CSV');

        self::assertSame(1, ((array) $report['summary'])['tasks'], json_encode($report, JSON_THROW_ON_ERROR));
        self::assertSame(['The file: column "Surprise" is not part of the template and was ignored'], $report['warnings']);
        $task = $this->register()[0];
        self::assertSame(['2026-10-09', 'Cash'], [$task['due_date'], $task['finance_statement_area']]);
        self::assertSame([['format' => 'csv', 'bytes' => 1]], $this->reader->reads);
    }

    public function test_a_project_only_csv_creates_or_finds_each_project(): void
    {
        $this->setup->createProject($this->admin, ['name' => 'Month End']);
        $this->reader->sheets = [];
        $this->reader->withSheet(null, [['Project name', 'Status'], ['Year End', 'planning'], ['Month End', 'active']]);

        $report = $this->load($this->admin, false, 'projects.csv');

        self::assertSame(['created', 'exists'], array_column($this->lines($report['projects']), 'action'));
        self::assertSame(['Month End', 'Year End'], array_column($this->setup->listProjects($this->admin), 'name'));
        self::assertSame(['active', 'active'], array_column($this->setup->listProjects($this->admin), 'status'), 'Planning is read as Active');
    }

    public function test_files_that_are_not_the_template_are_refused_as_a_whole(): void
    {
        $this->reader->sheets = [];
        foreach ([
            ['x.pdf', 'x', 'Upload the import template as .xlsx or .csv'],
            ['x.csv', '', 'The file is empty'],
            ['x.xlsx', str_repeat('a', 5 * 1024 * 1024 + 1), 'The file is larger than 5 MB'],
        ] as [$filename, $bytes, $message]) {
            try {
                $this->import->run($this->admin, $filename, $bytes, true);
                self::fail('Expected a refusal for ' . $filename);
            } catch (TaskImportFailed $e) {
                self::assertSame($message, $e->getMessage());
                self::assertSame(422, $e->httpStatus());
            }
        }
        // A CSV with no template column, and a workbook with no usable sheet.
        $this->reader->withSheet(null, [['Name', 'Amount'], ['A', 1]]);
        try {
            $this->import->run($this->admin, 'x.csv', 'x', true);
            self::fail('Expected a refusal.');
        } catch (TaskImportFailed $e) {
            self::assertSame('The file is not the import template: it needs a "Task Name" or a "Project name" column', $e->getMessage());
        }
        $this->reader->sheets = [];
        $this->reader->withSheet('Notes', [['Name', 'Amount']])->withSheet('Blank', []);
        try {
            $this->import->run($this->admin, 'x.xlsx', 'x', true);
            self::fail('Expected a refusal.');
        } catch (TaskImportFailed $e) {
            self::assertSame('No sheet in that workbook has a "Task Name" or a "Project name" column', $e->getMessage());
        }
        self::assertSame([], $this->audit->events);
    }

    public function test_an_extra_notes_sheet_is_ignored_when_another_sheet_is_the_template(): void
    {
        $this->reader->sheets = [];
        [, $tasks] = ImportFixtures::sheets(tasks: [self::row()]);
        $this->reader->withSheet('Read me', [['Fill in the other sheet']])->withSheet('Tasks', $tasks);

        $report = $this->load($this->admin);

        self::assertSame(1, ((array) $report['summary'])['tasks']);
    }

    public function test_projects_need_the_setup_permission_and_so_do_the_tasks_that_name_them(): void
    {
        $this->file(tasks: [self::row(), self::row('Accruals')]);

        $report = $this->load($this->member, false);

        self::assertSame(['error'], array_column($this->lines($report['projects']), 'action'));
        self::assertSame(['error', 'error'], array_column($this->lines($report['tasks']), 'action'));
        self::assertStringContainsString('permission to create projects', Typed::string(Typed::list($this->lines($report['tasks'])[0]['errors'])[0]));
        self::assertSame([], $this->register());
    }

    public function test_a_member_without_setup_may_import_tasks_into_projects_that_exist(): void
    {
        $this->setup->createProject($this->admin, ['name' => 'Month End']);
        $this->file(tasks: [self::row(cells: ['Preparer' => 'Una User'])]);

        $report = $this->load($this->member, false);

        self::assertSame(1, ((array) $report['summary'])['tasks']);
        self::assertSame(['11'], array_column($this->tasks->list($this->member), 'created_by_id'));
    }

    // ---- the cell rules -------------------------------------------------------------------

    /** @return iterable<string, array{mixed, ?string}> */
    public static function dates(): iterable
    {
        yield 'a date cell' => [new DateTimeImmutable('2026-10-09 00:00:00', new DateTimeZone('UTC')), '2026-10-09'];
        yield 'iso' => ['2026-10-09', '2026-10-09'];
        yield 'iso with a time' => ['2026-10-09 13:45:00', '2026-10-09'];
        yield 'iso with T' => ['2026-10-09T13:45:00', '2026-10-09'];
        yield 'iso without zeros' => ['2026-1-9', '2026-01-09'];
        yield 'day first slash' => ['09/10/2026', '2026-10-09'];
        yield 'day first dot' => ['9.10.2026', '2026-10-09'];
        yield 'day first dash' => ['09-10-26', '2026-10-09'];
        yield 'day month name' => ['9 Oct 2026', '2026-10-09'];
        yield 'dashed month name' => ['09-Oct-26', '2026-10-09'];
        yield 'long month name' => ['9 October 2026', '2026-10-09'];
        yield 'an Excel serial as a number' => [46_304.0, '2026-10-09'];
        yield 'an Excel serial as text' => ['46304', '2026-10-09'];
        yield 'a serial with a fraction' => ['46304.75', '2026-10-09'];
        yield 'blank' => ['', null];
        yield 'null' => [null, null];
        yield 'american order is not guessed' => ['10/13/2026', null];
        yield 'not a real day' => ['31/02/2026', null];
        yield 'words' => ['soon', null];
    }

    #[DataProvider('dates')]
    public function test_a_due_date_is_read_from_every_format_the_source_reads(mixed $cell, ?string $expected): void
    {
        $this->file(tasks: [self::row(cells: ['Due Date' => $cell])]);

        $report = $this->load($this->admin, false);

        $line = $this->lines($report['tasks'])[0];
        if ($expected === null && $cell !== null && $cell !== '') {
            self::assertSame('error', $line['action']);
            self::assertStringContainsString('is not a date (use dd/mm/yyyy)', Typed::string(Typed::list($line['errors'])[0]));

            return;
        }
        self::assertSame('created', $line['action'], json_encode($line, JSON_THROW_ON_ERROR));
        self::assertSame($expected ?? '', $this->register()[0]['due_date']);
    }

    /** @return iterable<string, array{mixed, ?int}> */
    public static function progresses(): iterable
    {
        yield 'a whole number' => [40, 40];
        yield 'a float' => [40.0, 40];
        yield 'a percent sign' => ['50%', 50];
        yield 'with a space' => [' 50 % ', 50];
        yield 'rounded half to even' => ['2.5', 2];
        yield 'rounded up' => ['2.6', 3];
        yield 'blank is none' => ['', 0];
        yield 'null is none' => [null, 0];
        yield 'over' => ['101', null];
        yield 'under' => ['-1', null];
        yield 'text' => ['lots', null];
    }

    #[DataProvider('progresses')]
    public function test_progress_is_a_number_from_0_to_100(mixed $cell, ?int $expected): void
    {
        $this->file(tasks: [self::row(cells: ['Progress %' => $cell])]);

        $line = $this->lines($this->load($this->admin, false)['tasks'])[0];

        if ($expected === null) {
            self::assertSame('error', $line['action']);
            self::assertStringStartsWith('Progress % must be a number from 0 to 100, not "', Typed::string(Typed::list($line['errors'])[0]));

            return;
        }
        self::assertSame('created', $line['action']);
        self::assertSame($expected, $this->register()[0]['progress']);
    }

    public function test_yes_and_no_accept_the_sources_words(): void
    {
        foreach (['Yes', 'y', 'TRUE', '1', 'x', true] as $yes) {
            $this->file(tasks: [self::row('T ' . json_encode($yes), cells: ['Mark as Urgent' => $yes])]);
            $this->load($this->admin, false);
        }
        foreach (['No', 'n', 'false', '0', '', null, false] as $no) {
            $this->file(tasks: [self::row('F ' . json_encode($no), cells: ['Mark as Urgent' => $no])]);
            $this->load($this->admin, false);
        }

        $urgent = [];
        foreach ($this->register() as $task) {
            $urgent[substr(Typed::string($task['name']), 0, 1)][] = $task['urgent'];
        }
        self::assertSame([true], array_values(array_unique(Typed::list($urgent['T']), SORT_REGULAR)));
        self::assertSame([false], array_values(array_unique(Typed::list($urgent['F']), SORT_REGULAR)));
        self::assertCount(13, $this->register());
    }

    public function test_status_and_category_spellings_are_read_ignoring_case_and_punctuation(): void
    {
        $this->file(tasks: [
            self::row('A', cells: ['Status' => 'In Progress', 'Category' => 'Ad hoc']),
            self::row('B', cells: ['Status' => 'done', 'Category' => 'AD-HOC']),
            self::row('C', cells: ['Status' => '', 'Category' => '']),
            self::row('D', cells: ['Status' => 'Complete']),
            self::row('E', cells: ['Status' => 'On-Hold']),
            self::row('F', cells: ['Status' => 'ACTIVE']),
            // Every earlier name for open work reads as Active (13 Oct 2026).
            self::row('G', cells: ['Status' => 'Working on it']),
            self::row('H', cells: ['Status' => 'not started']),
            self::row('I', cells: ['Status' => 'Stuck']),
            self::row('J', cells: ['Status' => 'Ready for review']),
        ]);

        $this->load($this->admin, false);

        $by = array_column($this->register(), null, 'name');
        self::assertSame(['active', 'ad_hoc'], [$by['A']['status'], $by['A']['category']]);
        self::assertSame(['completed', 'ad_hoc'], [$by['B']['status'], $by['B']['category']]);
        self::assertSame(['active', 'routine'], [$by['C']['status'], $by['C']['category']]);
        self::assertSame('completed', $by['D']['status']);
        self::assertSame('on_hold', $by['E']['status']);
        self::assertSame(['active', 'active', 'active', 'active', 'active'], [$by['F']['status'], $by['G']['status'], $by['H']['status'], $by['I']['status'], $by['J']['status']]);
    }

    public function test_a_statement_area_that_is_not_listed_is_kept_as_typed_with_a_warning(): void
    {
        $this->setup->createArea(['name' => 'Cash']);
        $this->file(tasks: [self::row('A', cells: ['Finance Statement Area' => ' cash ']), self::row('B', cells: ['Finance Statement Area' => 'Income Statement: Revenue'])]);

        $report = $this->load($this->admin, false);

        $lines = $this->lines($report['tasks']);
        self::assertSame([], $lines[0]['warnings']);
        self::assertSame(['"Income Statement: Revenue" is not in the Statements list; kept as typed'], $lines[1]['warnings']);
        self::assertSame('Income Statement: Revenue', array_column($this->register(), 'finance_statement_area', 'name')['B']);
    }

    // ---- limits, the project sheet, a duplicate across projects and entities ---------------

    public function test_a_file_may_hold_at_most_a_thousand_rows(): void
    {
        $this->file(tasks: array_map(static fn (int $i): array => self::row('Task ' . $i), range(1, 1000)));
        self::assertSame(1000, ((array) $this->load($this->admin)['summary'])['tasks']);

        $this->file(tasks: array_map(static fn (int $i): array => self::row('Task ' . $i), range(1, 1001)));
        $this->expectException(TaskImportFailed::class);
        $this->expectExceptionMessage('Import up to 1000 rows at a time');
        $this->load($this->admin);
    }

    public function test_the_project_sheet_is_read_first_with_its_own_rules(): void
    {
        $this->file(
            projects: [['Close', 'd', '', ''], ['close', '', 'Active', ''], ['Odd', '', 'Someday', 'tomorrow'], ['', '', '', ''], [str_repeat('x', 201), '', '', '']],
            tasks: [self::row(project: 'CLOSE')],
        );

        $report = $this->load($this->admin, false);

        $projects = $this->lines($report['projects']);
        self::assertSame(['created', 'duplicate', 'error', 'error'], array_column($projects, 'action'));
        self::assertSame(['Repeats row 2'], $projects[1]['warnings']);
        self::assertSame(['Status "Someday" must be Active, Completed or On Hold', 'Target Date "tomorrow" is not a date (use dd/mm/yyyy)'], $projects[2]['errors']);
        self::assertSame(['Project name must be at most 200 characters'], $projects[3]['errors']);
        self::assertSame('active', $this->setup->listProjects($this->admin)[0]['status'], 'a project with no status is active');
        self::assertSame(1, ((array) $report['summary'])['tasks']);
    }

    public function test_the_same_name_in_another_project_or_entity_is_not_a_duplicate(): void
    {
        $this->setup->createEntity(['name' => 'Other Pty', 'code' => 'OP']);
        $this->file(tasks: [self::row('Rec', 'A'), self::row('Rec', 'B'), self::row('Rec', 'A', ['Entity' => 'Other Pty']), self::row('Rec', 'A')]);

        $report = $this->load($this->admin, false);

        self::assertSame(['created', 'created', 'created', 'duplicate'], array_column($this->lines($report['tasks']), 'action'));
        self::assertCount(3, $this->register());
    }

    public function test_a_task_the_caller_cannot_see_still_counts_as_a_duplicate(): void
    {
        $this->setup->createProject($this->admin, ['name' => 'Month End']);
        $this->tasks->create($this->admin, ['name' => 'Bank rec', 'project_id' => $this->setup->listProjects($this->admin)[0]['id'], 'entity_id' => $this->setup->listEntities()[0]['id'], 'preparer_id' => 12]);
        $this->file(tasks: [self::row()]);

        $report = $this->load($this->member, false);

        self::assertSame(['duplicate'], array_column($this->lines($report['tasks']), 'action'));
        self::assertSame([], $this->tasks->list($this->member), 'and the member sees none of it');
    }

    public function test_a_row_that_fails_to_save_is_reported_and_the_others_stay(): void
    {
        $this->file(tasks: [self::row('Good'), self::row(str_repeat('n', 301)), self::row('Also good')]);

        $report = $this->load($this->admin, false);

        $lines = $this->lines($report['tasks']);
        self::assertSame(['created', 'error', 'created'], array_column($lines, 'action'));
        self::assertSame(['Task Name must be at most 300 characters'], $lines[1]['errors']);
        self::assertCount(2, $this->register());
    }

    // ---- the audit row (finding F3) ---------------------------------------------------------

    public function test_a_real_import_writes_one_audit_event_with_counts_the_file_name_and_the_actor_and_no_row_content(): void
    {
        $this->file(tasks: [self::row('Secret task name'), self::row('Bad', cells: ['Preparer' => 'Nobody'])]);

        $this->load($this->admin, false, 'sept-close.xlsx');

        $events = $this->audit->events;
        self::assertCount(1, $events);
        self::assertSame('finance.task.import', $events[0]->action);
        self::assertSame('TASK', $events[0]->application);
        self::assertSame(13, $events[0]->actorUserId);
        self::assertSame(['filename' => 'sept-close.xlsx', 'projects' => 1, 'projects_existing' => 0, 'tasks' => 1, 'tasks_duplicate' => 0, 'errors' => 1, 'tasks_updated' => 0, 'tasks_unchanged' => 0], $events[0]->details);
        self::assertStringNotContainsString('Secret task name', json_encode($events[0], JSON_THROW_ON_ERROR));
    }

    // ---- notes (ported with the importer's tests) -------------------------------------------

    public function test_notes_are_stored_and_edited(): void
    {
        $task = $this->tasks->create($this->admin, ['name' => 'Close the books', 'notes' => 'Waiting on the bank', 'preparer_id' => 11]);
        self::assertSame('Waiting on the bank', $task['notes']);

        $patched = $this->tasks->update($this->admin, Typed::string($task['id']), ['notes' => '']);

        self::assertSame('', $patched['notes']);
    }

    public function test_estimated_time_is_free_text_stored_edited_and_capped(): void
    {
        $task = $this->tasks->create($this->admin, ['name' => 'Close the books', 'time_spent' => ' about 2 hours ', 'preparer_id' => 11]);
        self::assertSame('about 2 hours', $task['time_spent']);
        self::assertSame('', $this->tasks->create($this->admin, ['name' => 'Accruals', 'preparer_id' => 11])['time_spent']);

        self::assertSame('half a day', $this->tasks->update($this->admin, Typed::string($task['id']), ['time_spent' => 'half a day'])['time_spent']);
        self::assertSame('half a day', $this->tasks->update($this->admin, Typed::string($task['id']), ['notes' => 'x'])['time_spent'], 'kept when not sent');

        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('Estimated time must be at most 200 characters');
        $this->tasks->update($this->admin, Typed::string($task['id']), ['time_spent' => str_repeat('a', 201)]);
    }

    public function test_an_estimated_time_column_loads_and_a_file_without_it_keeps_what_is_there(): void
    {
        $head = ImportFixtures::ESTIMATED_TIME_HEAD;
        $this->exported([self::exportRow(cells: ['Estimated Time' => '3 days'], head: $head)], $head);
        $this->load($this->admin, false);
        $task = $this->register()[0];
        self::assertSame('3 days', $task['time_spent']);

        // An export from before the column: the row is unchanged and the time stays.
        $this->exported([self::exportRow(cells: ['Task ID' => $task['id'], 'Status' => 'Active'])]);
        self::assertSame(['unchanged'], array_column($this->lines($this->load($this->admin, false)['tasks']), 'action'));
        self::assertSame('3 days', $this->register()[0]['time_spent']);

        $this->exported([self::exportRow(cells: ['Task ID' => $task['id'], 'Estimated Time' => str_repeat('a', 201)], head: $head)], $head);
        self::assertSame(['Estimated Time must be at most 200 characters'], Typed::list($this->lines($this->load($this->admin)['tasks'])[0]['errors']));

        $this->exported([self::exportRow(cells: ['Task ID' => $task['id'], 'Estimated Time' => ''], head: $head)], $head);
        $this->load($this->admin, false);
        self::assertSame('', $this->register()[0]['time_spent'], 'a blank cell clears it');
    }

    // ---- the export loaded back (Ming and Nick Han, 16 Oct 2026) ------------------------

    public function test_a_renamed_project_in_an_exported_file_moves_the_tasks_instead_of_copying_them(): void
    {
        $this->file(tasks: [self::row(), self::row('Accruals')]);
        $this->load($this->admin, false);
        [$first, $second] = array_map(static fn (array $t): string => Typed::string($t['id']), $this->register());

        $this->exported([
            self::exportRow('Bank rec', 'FY2027 Month End', ['Task ID' => $first]),
            self::exportRow('Accruals', 'FY2027 Month End', ['Task ID' => $second]),
        ]);
        $preview = $this->load($this->admin);
        self::assertSame(['projects' => 1, 'projects_existing' => 0, 'tasks' => 0, 'tasks_duplicate' => 0, 'errors' => 0, 'tasks_updated' => 2, 'tasks_unchanged' => 0], $preview['summary']);
        self::assertSame(['update', 'update'], array_column($this->lines($preview['tasks']), 'action'));
        self::assertContains('Moves to project "FY2027 Month End"', Typed::list($this->lines($preview['tasks'])[0]['warnings']));

        $real = $this->load($this->admin, false);

        self::assertSame(2, ((array) $real['summary'])['tasks_updated']);
        $register = $this->register();
        self::assertCount(2, $register, 'no copies');
        self::assertEqualsCanonicalizing([$first, $second], array_column($register, 'id'), 'the same tasks, changed in place');
        self::assertSame(['FY2027 Month End', 'FY2027 Month End'], array_column($register, 'project_name'));
    }

    public function test_an_exported_row_that_was_not_edited_changes_nothing(): void
    {
        $this->file(tasks: [self::row(cells: ['Status' => 'Active'])]);
        $this->load($this->admin, false);
        $task = $this->register()[0];

        $this->exported([self::exportRow(cells: ['Task ID' => $task['id'], 'Status' => 'Active'])]);
        $report = $this->load($this->admin, false);

        self::assertSame(['unchanged'], array_column($this->lines($report['tasks']), 'action'), json_encode($report, JSON_THROW_ON_ERROR));
        self::assertSame($task['updated_date'], $this->register()[0]['updated_date']);
    }

    public function test_an_exported_file_loads_every_field_of_the_form_type_priority_and_group_included(): void
    {
        $this->exported([self::exportRow(cells: [
            'Type' => 'Ad-hoc', 'Priority' => 'High', 'Group' => 'Reconciliations', 'Notes' => 'n', 'Description' => 'd', 'Requirements' => 'r',
            'Finance Statement Area' => 'Cash', 'Status' => 'On Hold', 'Progress %' => '25', 'Due Date' => '09/10/2026', 'Mark as Urgent' => 'Yes',
        ])]);

        $preview = $this->load($this->admin);
        self::assertContains('Group "Reconciliations" is new in "Month End"; it will be made', Typed::list($this->lines($preview['tasks'])[0]['warnings']));
        $this->load($this->admin, false);

        $task = $this->register()[0];
        $project = Typed::string($this->setup->listProjects($this->admin)[0]['id']);
        $groups = $this->groups->groups($project);
        self::assertSame(['Reconciliations'], array_column($groups, 'name'));
        foreach ([
            'category' => 'ad_hoc', 'priority' => 'high', 'group_id' => $groups[0]['id'], 'notes' => 'n', 'description' => 'd', 'requirements' => 'r',
            'finance_statement_area' => 'Cash', 'status' => 'on_hold', 'progress' => 25, 'due_date' => '2026-10-09', 'urgent' => true,
        ] as $field => $expected) {
            self::assertSame($expected, $task[$field], $field);
        }

        // Blank cells are blank: no priority, no group.
        $this->exported([self::exportRow(cells: ['Task ID' => $task['id'], 'Priority' => '', 'Group' => ''])]);
        $this->load($this->admin, false);
        self::assertSame([null, null], [$this->register()[0]['priority'], $this->register()[0]['group_id']]);
    }

    public function test_a_task_id_that_is_unknown_or_binned_is_refused_and_a_bad_priority_too(): void
    {
        $this->file(tasks: [self::row()]);
        $this->load($this->admin, false);
        $binned = Typed::string($this->register()[0]['id']);
        $this->tasks->delete(new TaskActor(13, 'Ada Admin', 'ada@example.test', [Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_DELETE]), $binned);

        $this->exported([
            self::exportRow('Bank rec', cells: ['Task ID' => $binned]),
            self::exportRow('Accruals', cells: ['Task ID' => 'nope']),
            self::exportRow('Payroll', cells: ['Priority' => 'Urgent']),
        ]);
        $report = $this->load($this->admin);

        self::assertSame([
            ['Task ID ' . $binned . ' is in the Bin; restore it first'],
            ['Task ID nope is not a task in Tri-Task (clear the Task ID to add it as a new task)'],
            ['Priority "Urgent" must be Critical, High, Medium or Low'],
        ], array_column($this->lines($report['tasks']), 'errors'));
    }

    public function test_a_task_the_caller_may_not_change_is_refused_in_the_preview(): void
    {
        $this->file(tasks: [self::row(cells: ['Preparer' => 'Mo Manager', 'Reviewer' => 'Una User'])]);
        $this->load($this->admin, false);
        $id = $this->register()[0]['id'];

        $this->exported([self::exportRow('Bank rec 2', 'Month End', ['Task ID' => $id, 'Preparer' => 'Mo Manager', 'Reviewer' => 'Una User'])]);
        $report = $this->load($this->member);

        self::assertSame([['You do not have permission to change this task']], array_column($this->lines($report['tasks']), 'errors'));
    }

    public function test_a_blank_project_keeps_the_tasks_project_or_files_a_new_task_under_general(): void
    {
        $this->file(tasks: [self::row()]);
        $this->load($this->admin, false);
        $task = $this->register()[0];

        $this->exported([
            self::exportRow('Bank rec', '', ['Task ID' => $task['id'], 'Notes' => 'kept in its project']),
            self::exportRow('Petty cash', ''),
        ]);
        $preview = $this->load($this->admin);
        self::assertSame(['update', 'create'], array_column($this->lines($preview['tasks']), 'action'), json_encode($preview, JSON_THROW_ON_ERROR));
        self::assertSame([], $preview['projects']);
        $this->load($this->admin, false);

        $byName = array_column($this->register(), null, 'name');
        self::assertSame(['Month End', 'kept in its project'], [$byName['Bank rec']['project_name'], $byName['Bank rec']['notes']]);
        self::assertSame('General', $byName['Petty cash']['project_name']);
    }

    public function test_a_member_cannot_make_a_new_group_by_import(): void
    {
        $this->setup->createProject($this->admin, ['name' => 'Month End']);
        $this->exported([self::exportRow(cells: ['Group' => 'Reconciliations'])]);

        $report = $this->load($this->member);

        self::assertSame([['Group "Reconciliations" is not a group of "Month End"']], array_column($this->lines($report['tasks']), 'errors'));
    }

    // ---- several preparers and reviewers in one cell (23 Oct 2026) -----------------------

    public function test_a_cell_may_name_several_people_separated_by_semicolons_and_an_old_single_name_still_loads(): void
    {
        $this->file(tasks: [
            self::row('Shared', cells: ['Preparer' => 'Una User; mo@example.test', 'Reviewer' => 'Mo Manager;Una User; ']),
            self::row('Single', cells: ['Preparer' => 'Una User', 'Reviewer' => '']),
            self::row('Bad', cells: ['Preparer' => 'Una User; Nobody Here; Ada Admin']),
        ]);

        $report = $this->load($this->admin, false);

        $lines = $this->lines($report['tasks']);
        self::assertSame(['created', 'created', 'error'], array_column($lines, 'action'));
        self::assertSame(['Preparer "Nobody Here" is not a member of Tri-Task', 'Preparer "Ada Admin" matches more than one person'], $lines[2]['errors']);
        $byName = [];
        foreach ($this->register() as $task) {
            $byName[Typed::string($task['name'])] = $task;
        }
        self::assertSame([['11', '12'], ['12', '11']], [$byName['Shared']['preparer_ids'], $byName['Shared']['reviewer_ids']]);
        self::assertSame([['11'], []], [$byName['Single']['preparer_ids'], $byName['Single']['reviewer_ids']]);
        self::assertSame(['11', null], [$byName['Single']['preparer_id'], $byName['Single']['reviewer_id']]);
    }

    public function test_an_exported_file_with_several_names_changes_nothing_until_a_name_changes(): void
    {
        $this->file(tasks: [self::row(cells: ['Preparer' => 'Una User; Mo Manager'])]);
        $this->load($this->admin, false);
        $id = Typed::string($this->register()[0]['id']);

        $this->exported([self::exportRow('Bank rec', 'Month End', ['Task ID' => $id, 'Preparer' => 'Mo Manager; Una User', 'Reviewer' => 'Mo Manager'])]);
        $same = $this->load($this->admin);
        self::assertSame(['unchanged'], array_column($this->lines($same['tasks']), 'action'), 'the same people in another order');

        $this->exported([self::exportRow('Bank rec', 'Month End', ['Task ID' => $id, 'Preparer' => 'Mo Manager', 'Reviewer' => 'Mo Manager'])]);
        $this->load($this->admin, false);
        self::assertSame(['12'], $this->register()[0]['preparer_ids']);
    }
}
