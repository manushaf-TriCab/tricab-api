<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\SpreadsheetReader;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\SpreadsheetSheet;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Domain\TaskImportFailed;

/**
 * The spreadsheet import (route 47), `server/importer.py` ported rule for rule: Nick Han's template (a
 * "Project import template" sheet and a "Task import template" sheet, or either as CSV), recognised by
 * its headings, not by name or position.
 *
 * Two passes over the same code: a dry run (the default) reports what each row would do and writes
 * nothing; a real run does it. **Nothing is guessed:** a row whose entity, preparer, reviewer, date, status,
 * category or progress cannot be read is reported ("Not imported") and left out, and the rest still load.
 * A task already in the register (same name, project and entity, whoever holds it) or repeated earlier
 * in the file is skipped, so the same file can be loaded twice. A project named only on task rows is
 * created once, and only if one of its tasks will load.
 *
 * It applies row by row through `TaskSetupService` and `TaskService` **as the caller**, not in one
 * transaction (it reports per row, by design); a real run writes one audit event with the counts, the file
 * name and the actor (finding F3), which the source's log line did not keep.
 */
final class TaskImportService
{
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;

    public const MAX_ROWS = 1000;

    public const ACTION_AUDIT = 'finance.task.import';

    private const TASK_HEADINGS = [
        'entity' => 'entity', 'legal entity' => 'entity',
        'task name' => 'name', 'task' => 'name',
        'project' => 'project',
        'description' => 'description',
        'requirements' => 'requirements',
        'notes' => 'notes',
        'finance statement area' => 'finance_statement_area',
        'financial statement area' => 'finance_statement_area',
        'financial statements' => 'finance_statement_area',
        'category' => 'category',
        'status' => 'status',
        'preparer' => 'preparer', 'preparer (assignee)' => 'preparer',
        'reviewer' => 'reviewer',
        'progress %' => 'progress', 'progress' => 'progress',
        'due date' => 'due_date',
        'due date requested' => 'due_date_requested',
        'mark as urgent' => 'urgent', 'urgent' => 'urgent',
    ];

    private const PROJECT_HEADINGS = [
        'project name' => 'name',
        'description' => 'description',
        'status' => 'status',
        'target date' => 'target_date',
    ];

    private const TRUE = ['yes', 'y', 'true', '1', 'x'];
    private const FALSE = ['no', 'n', 'false', '0', ''];
    private const STATUS = ['inprogress' => 'in_progress', 'completed' => 'completed', 'complete' => 'completed', 'done' => 'completed'];
    private const CATEGORY = ['routine' => 'routine', 'adhoc' => 'ad_hoc'];
    private const PROJECT_STATUS = ['planning' => 'planning', 'active' => 'active', 'completed' => 'completed', 'onhold' => 'on_hold'];
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly TaskSetupService $setup,
        private readonly TaskService $tasks,
        private readonly TaskSetupStore $setupStore,
        private readonly TaskStore $taskStore,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly FinanceAuditLog $audit,
        private readonly TaskTime $time,
    ) {
    }

    /**
     * @return array<string, mixed> `{filename, dry_run, projects, tasks, warnings, summary}`
     */
    public function run(TaskActor $actor, string $filename, string $content, bool $dryRun): array
    {
        $sheets = $this->readFile($filename, $content);
        $warnings = [];
        foreach ($sheets as $sheet) {
            foreach ($sheet['unknown'] as $name) {
                $warnings[] = $sheet['label'] . ': column "' . $name . '" is not part of the template and was ignored';
            }
        }

        $memberRows = [];
        foreach ($this->members->members() as $member) {
            $memberRows[] = ['id' => $member->userId, 'keys' => [self::key($member->name !== '' ? $member->name : $member->email), self::key($member->email)]];
        }
        $memberLookup = self::lookup($memberRows);
        $entityRows = [];
        foreach ($this->setupStore->entities() as $entity) {
            $entityRows[] = ['id' => $entity->id, 'keys' => [self::key($entity->name), self::key($entity->code)]];
        }
        $entityLookup = self::lookup($entityRows);
        $areaNames = [];
        foreach ($this->setupStore->areas() as $area) {
            $areaNames[self::key($area->name)] = true;
        }
        $projectIds = [];                                   // lower-cased name to id, live projects
        foreach ($this->setupStore->projects(false) as $project) {
            $projectIds[self::key($project->name)] = $project->id;
        }
        $existingTasks = [];                                // "name|project|entity" of every live task
        foreach ($this->taskStore->liveTaskIdentities() as $row) {
            $existingTasks[self::identity(self::key($row['name']), $row['project_id'], $row['entity_id'])] = true;
        }
        $mayCreateProjects = $actor->can(Module::PERMISSION_TASK_SETUP_MANAGE);

        // ---- projects: the Project sheet first, then any project a task names ----
        /** @var list<ImportLine> $projects */
        $projects = [];
        /** @var array<string, ImportLine> $planned lower-cased name to the line that will create it */
        $planned = [];

        $planProject = static function (int $row, string $name, array $payload, string $source, array $errors) use (&$projects, &$planned, $projectIds, $mayCreateProjects): ImportLine {
            $line = new ImportLine($row, $name);
            $line->source = $source;
            $line->errors = $errors;
            $key = self::key($name);
            if ($name === '') {
                $line->errors[] = 'Project name is required';
            } elseif (mb_strlen($name) > 200) {
                $line->errors[] = 'Project name must be at most 200 characters';
            } elseif (isset($projectIds[$key])) {
                $line->action = 'exists';
            } elseif (isset($planned[$key])) {
                $line->action = 'duplicate';
                $line->warnings[] = 'Repeats row ' . $planned[$key]->row;
            } elseif (!$mayCreateProjects) {
                $line->errors[] = 'You do not have permission to create projects';
            }
            if ($line->errors !== []) {
                $line->action = 'error';
            } elseif ($line->action === '') {
                $line->action = 'create';
                $line->payload = $payload;
                $planned[$key] = $line;
            }
            $projects[] = $line;

            return $line;
        };

        foreach ($sheets as $sheet) {
            if ($sheet['kind'] !== 'projects') {
                continue;
            }
            foreach ($sheet['rows'] as [$rowNumber, $row]) {
                $errors = [];
                $name = self::cellText($row['name'] ?? null);
                $letters = self::letters($row['status'] ?? null);
                $status = self::PROJECT_STATUS[$letters !== '' ? $letters : 'active'] ?? null;
                if ($status === null) {
                    $errors[] = 'Status "' . self::cellText($row['status'] ?? null) . '" must be Planning, Active, Completed or On Hold';
                }
                $payload = [
                    'name' => $name,
                    'description' => self::cellText($row['description'] ?? null),
                    'status' => $status,
                    'target_date' => self::date($row['target_date'] ?? null, 'Target Date', $errors),
                ];
                $planProject($rowNumber, $name, $payload, 'Project sheet', $errors);
            }
        }

        // ---- tasks ----
        /** @var list<ImportLine> $taskLines */
        $taskLines = [];
        $seenInFile = [];
        /** @var array<string, ImportLine> $refusedProjects */
        $refusedProjects = [];
        foreach ($sheets as $sheet) {
            if ($sheet['kind'] !== 'tasks') {
                continue;
            }
            foreach ($sheet['rows'] as [$rowNumber, $row]) {
                $errors = [];
                $name = self::cellText($row['name'] ?? null);
                $projectName = self::cellText($row['project'] ?? null);
                $line = new ImportLine($rowNumber, $name);
                $line->project = $projectName;
                if ($name === '') {
                    $errors[] = 'Task Name is required';
                } elseif (mb_strlen($name) > 300) {
                    $errors[] = 'Task Name must be at most 300 characters';
                }

                $projectKey = self::key($projectName);
                if ($projectName === '') {
                    $errors[] = 'Project is required';
                } elseif (!isset($projectIds[$projectKey]) && !isset($planned[$projectKey])) {
                    // Named on a task but neither in the register nor on the Project sheet. Reported once,
                    // however many tasks name it.
                    $refused = $refusedProjects[$projectKey] ?? $planProject($rowNumber, $projectName, ['name' => $projectName, 'status' => 'active'], 'Task sheet', []);
                    if ($refused->action === 'error') {
                        $refusedProjects[$projectKey] = $refused;
                        $errors[] = 'Project "' . $projectName . '": ' . implode('; ', $refused->errors);
                    }
                }

                $letters = self::letters($row['status'] ?? null);
                $status = self::STATUS[$letters !== '' ? $letters : 'inprogress'] ?? null;
                if ($letters === 'overdue') {
                    $status = 'in_progress';
                    $line->warnings[] = 'Overdue is worked out from the due date; imported as In Progress';
                } elseif ($status === null) {
                    $errors[] = 'Status "' . self::cellText($row['status'] ?? null) . '" must be In Progress or Completed';
                }
                $categoryLetters = self::letters($row['category'] ?? null);
                $category = self::CATEGORY[$categoryLetters !== '' ? $categoryLetters : 'routine'] ?? null;
                if ($category === null) {
                    $errors[] = 'Category "' . self::cellText($row['category'] ?? null) . '" must be Routine or Ad-hoc';
                }
                $area = self::cellText($row['finance_statement_area'] ?? null);
                if (mb_strlen($area) > 300) {
                    $errors[] = 'Finance Statement Area must be at most 300 characters';
                } elseif ($area !== '' && !isset($areaNames[self::key($area)])) {
                    $line->warnings[] = '"' . $area . '" is not in the Statements list; kept as typed';
                }

                $entityId = self::resolve($row['entity'] ?? null, $entityLookup, 'Entity', $errors, 'is not an entity in Tri-Task');
                $payload = [
                    'name' => $name,
                    'description' => self::cellText($row['description'] ?? null),
                    'requirements' => self::cellText($row['requirements'] ?? null),
                    'notes' => self::cellText($row['notes'] ?? null),
                    'finance_statement_area' => $area,
                    'category' => $category,
                    'status' => $status,
                    'entity_id' => $entityId,
                    'preparer_id' => self::resolve($row['preparer'] ?? null, $memberLookup, 'Preparer', $errors, 'is not a member of Tri-Task'),
                    'reviewer_id' => self::resolve($row['reviewer'] ?? null, $memberLookup, 'Reviewer', $errors, 'is not a member of Tri-Task'),
                    'progress' => self::progress($row['progress'] ?? null, $errors),
                    'due_date' => self::date($row['due_date'] ?? null, 'Due Date', $errors),
                    'due_date_requested' => self::yesNo($row['due_date_requested'] ?? null, 'Due Date Requested', $errors),
                    'urgent' => self::yesNo($row['urgent'] ?? null, 'Mark as Urgent', $errors),
                ];
                $line->errors = $errors;

                $identity = self::identity(self::key($name), $projectKey, $entityId);
                $existing = self::identity(self::key($name), $projectIds[$projectKey] ?? null, $entityId);
                if ($errors !== []) {
                    $line->action = 'error';
                } elseif (isset($existingTasks[$existing]) && isset($projectIds[$projectKey])) {
                    $line->action = 'duplicate';
                    $line->warnings[] = 'Already in the register (same name, project and entity)';
                } elseif (isset($seenInFile[$identity])) {
                    $line->action = 'duplicate';
                    $line->warnings[] = 'Repeats row ' . $seenInFile[$identity];
                } else {
                    $line->action = 'create';
                    $line->payload = $payload;
                    $line->projectKey = $projectKey;
                    $seenInFile[$identity] = $rowNumber;
                }
                $taskLines[] = $line;
            }
        }

        // A project that only tasks name is created for the tasks that will load; if none of them will, it
        // would be left behind empty.
        $wanted = [];
        foreach ($taskLines as $line) {
            if ($line->action === 'create') {
                $wanted[$line->projectKey] = true;
            }
        }
        $projects = array_values(array_filter(
            $projects,
            static fn (ImportLine $l): bool => !($l->source === 'Task sheet' && $l->action === 'create' && !isset($wanted[self::key($l->name)])),
        ));

        // ---- apply ----
        if (!$dryRun) {
            foreach ($projects as $line) {
                if ($line->action !== 'create') {
                    continue;
                }
                try {
                    $created = $this->setup->createProject($actor, $line->payload);
                    $projectIds[self::key($line->name)] = (string) $created['id'];
                    $line->action = 'created';
                } catch (TaskFailure $failure) {
                    $line->action = 'error';
                    $line->errors[] = $failure->getMessage();
                }
            }
            foreach ($taskLines as $line) {
                if ($line->action !== 'create') {
                    continue;
                }
                $projectId = $projectIds[$line->projectKey] ?? null;
                if ($projectId === null || $projectId === '') {
                    $line->action = 'error';
                    $line->errors[] = 'Project "' . $line->project . '" could not be created';
                    continue;
                }
                try {
                    $this->tasks->create($actor, [...$line->payload, 'project_id' => $projectId]);
                    $line->action = 'created';
                } catch (TaskFailure $failure) {
                    $line->action = 'error';
                    $line->errors[] = $failure->getMessage();
                }
            }
        }

        $count = static fn (array $lines, string $action): int => count(array_filter($lines, static fn (ImportLine $l): bool => $l->action === $action));
        $done = $dryRun ? 'create' : 'created';
        $summary = [
            'projects' => $count($projects, $done),
            'projects_existing' => $count($projects, 'exists') + $count($projects, 'duplicate'),
            'tasks' => $count($taskLines, $done),
            'tasks_duplicate' => $count($taskLines, 'duplicate'),
            'errors' => $count($projects, 'error') + $count($taskLines, 'error'),
        ];

        if (!$dryRun) {
            $this->audit->record(new FinanceAuditEvent(
                self::ACTION_AUDIT,
                'import',
                null,
                ['filename' => mb_substr($filename, 0, 200)] + $summary,
                $this->time->now(),
                $actor->userId,
                Module::APP_TASK,
            ));
        }

        return [
            'filename' => $filename,
            'dry_run' => $dryRun,
            'projects' => array_map(static fn (ImportLine $l): array => $l->toProject(), $projects),
            'tasks' => array_map(static fn (ImportLine $l): array => $l->toTask(), $taskLines),
            'warnings' => $warnings,
            'summary' => $summary,
        ];
    }

    // ==================================================================================
    // Reading the file
    // ==================================================================================

    /**
     * The recognised sheets of an `.xlsx` or `.csv` file.
     *
     * @return list<array{kind: string, rows: list<array{0: int, 1: array<string, mixed>}>, label: string, unknown: list<string>}>
     */
    private function readFile(string $filename, string $content): array
    {
        if ($content === '') {
            throw new TaskImportFailed('The file is empty');
        }
        if (strlen($content) > self::MAX_FILE_BYTES) {
            throw new TaskImportFailed('The file is larger than ' . intdiv(self::MAX_FILE_BYTES, 1024 * 1024) . ' MB');
        }
        $name = strtolower($filename);
        $sheets = [];
        if (str_ends_with($name, '.csv')) {
            foreach ($this->reader->read(SpreadsheetReader::FORMAT_CSV, $content) as $raw) {
                $sheet = self::sheet($raw->rows, 'The file');
                if ($sheet !== null) {
                    $sheets[] = $sheet;
                }
            }
        } elseif (str_ends_with($name, '.xlsx') || str_ends_with($name, '.xlsm')) {
            foreach ($this->reader->read(SpreadsheetReader::FORMAT_XLSX, $content) as $raw) {
                try {
                    $sheet = self::sheet($raw->rows, 'Sheet "' . ($raw->title ?? '') . '"');
                } catch (TaskImportFailed) {
                    continue;   // an extra sheet of notes is not an error; no usable sheet at all is
                }
                if ($sheet !== null) {
                    $sheets[] = $sheet;
                }
            }
            if ($sheets === []) {
                throw new TaskImportFailed('No sheet in that workbook has a "Task Name" or a "Project name" column');
            }
        } else {
            throw new TaskImportFailed('Upload the import template as .xlsx or .csv');
        }
        $total = 0;
        foreach ($sheets as $sheet) {
            $total += count($sheet['rows']);
        }
        if ($total > self::MAX_ROWS) {
            throw new TaskImportFailed('Import up to ' . self::MAX_ROWS . ' rows at a time');
        }

        return $sheets;
    }

    /**
     * One sheet as `{kind, rows: [[row number, {field: cell}]], label, unknown}`; null when it is empty, and
     * a sheet whose headings match neither template raises.
     *
     * @param list<list<mixed>> $rows
     * @return array{kind: string, rows: list<array{0: int, 1: array<string, mixed>}>, label: string, unknown: list<string>}|null
     */
    private static function sheet(array $rows, string $label): ?array
    {
        $headerAt = null;
        foreach ($rows as $i => $row) {
            foreach ($row as $cell) {
                if (self::cellText($cell) !== '') {
                    $headerAt = $i;
                    break 2;
                }
            }
        }
        if ($headerAt === null) {
            return null;
        }
        $headings = array_map(self::heading(...), $rows[$headerAt]);
        if (in_array('task name', $headings, true) || in_array('task', $headings, true)) {
            $kind = 'tasks';
            $known = self::TASK_HEADINGS;
        } elseif (in_array('project name', $headings, true)) {
            $kind = 'projects';
            $known = self::PROJECT_HEADINGS;
        } else {
            throw new TaskImportFailed($label . ' is not the import template: it needs a "Task Name" or a "Project name" column');
        }
        $fields = array_map(static fn (string $h): ?string => $known[$h] ?? null, $headings);
        $out = [];
        $count = count($rows);
        for ($i = $headerAt + 1; $i < $count; ++$i) {
            $row = $rows[$i];
            $any = false;
            foreach ($row as $cell) {
                if (self::cellText($cell) !== '') {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                continue;
            }
            $cells = [];
            foreach ($fields as $index => $field) {
                if ($field !== null && $index < count($row)) {
                    $cells[$field] = $row[$index];
                }
            }
            $out[] = [$i + 1, $cells];   // the file's own row number: the first row is 1
        }
        $unknown = [];
        foreach ($fields as $index => $field) {
            if ($field === null && $headings[$index] !== '') {
                $unknown[] = TaskRules::strip(self::cellText($rows[$headerAt][$index] ?? null));
            }
        }

        return ['kind' => $kind, 'rows' => $out, 'label' => $label, 'unknown' => $unknown];
    }

    // ==================================================================================
    // Reading a cell
    // ==================================================================================

    private static function heading(mixed $value): string
    {
        return mb_strtolower(TaskRules::strip(preg_replace('/\s+/u', ' ', self::raw($value)) ?? ''));
    }

    private static function letters(mixed $value): string
    {
        return preg_replace('/[^a-z]/', '', strtolower(self::raw($value))) ?? '';
    }

    /** `str(value or "")`. */
    private static function raw(mixed $value): string
    {
        if ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0) {
            return '';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return is_scalar($value) ? (is_bool($value) ? 'True' : (string) $value) : '';
    }

    /** `_cell_text`: None is empty, a whole float is an integer, anything else is its stripped text. */
    private static function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        return TaskRules::strip(is_scalar($value) ? (string) $value : '');
    }

    /** `_key`: whitespace collapsed, lower case. */
    private static function key(mixed $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', self::cellText($value)) ?? '');
    }

    private static function identity(string $name, ?string $project, int|string|null $entity): string
    {
        return $name . "\0" . ($project ?? '') . "\0" . ($entity === null ? '' : (string) $entity);
    }

    /**
     * `yyyy-MM-dd` from an Excel date, a serial number or Australian (day-first) text.
     *
     * @param list<string> $errors
     */
    private static function date(mixed $value, string $label, array &$errors): ?string
    {
        if ($value === null || self::cellText($value) === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $text = self::cellText($value);
        $valid = static fn (int $y, int $m, int $d): ?string => $y >= 1 && $y <= 9999 && checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
        $result = null;
        if (preg_match('/^\d{5}(\.\d+)?$/', $text) === 1) {   // an Excel serial that lost its date format (CSV)
            $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));

            return $base->modify('+' . (int) (float) $text . ' days')->format('Y-m-d');
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/s', $text, $m) === 1) {
            $result = $valid((int) $m[1], (int) $m[2], (int) $m[3]);
        } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/', $text, $m) === 1) {   // day first, as written in Australia
            $result = $valid((int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0), (int) $m[2], (int) $m[1]);
        } elseif (preg_match('/^(\d{1,2})[ \-]([A-Za-z]{3})[A-Za-z]*[ \-](\d{2}|\d{4})$/', $text, $m) === 1 && isset(self::MONTHS[strtolower($m[2])])) {
            $result = $valid((int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0), self::MONTHS[strtolower($m[2])], (int) $m[1]);
        }
        if ($result !== null) {
            return $result;
        }
        $errors[] = $label . ' "' . $text . '" is not a date (use dd/mm/yyyy)';

        return null;
    }

    /** @param list<string> $errors */
    private static function yesNo(mixed $value, string $label, array &$errors): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $text = mb_strtolower(self::cellText($value));
        if (in_array($text, self::TRUE, true)) {
            return true;
        }
        if (in_array($text, self::FALSE, true)) {
            return false;
        }
        $errors[] = $label . ' must be Yes or No, not "' . self::cellText($value) . '"';

        return false;
    }

    /** @param list<string> $errors */
    private static function progress(mixed $value, array &$errors): int
    {
        $text = TaskRules::strip(rtrim(self::cellText($value), '%'));
        if ($text === '') {
            return 0;
        }
        $number = is_numeric($text) ? (float) $text : -1.0;
        if ($number < 0 || $number > 100) {
            $errors[] = 'Progress % must be a number from 0 to 100, not "' . self::cellText($value) . '"';

            return 0;
        }

        return (int) round($number, 0, PHP_ROUND_HALF_EVEN);
    }

    // ==================================================================================
    // Lookups
    // ==================================================================================

    /**
     * `{lower-cased key value: [row id, ...]}` over several keys of the same rows.
     *
     * @param list<array{id: int|string, keys: list<string>}> $rows
     * @return array<string, list<int|string>>
     */
    private static function lookup(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ($row['keys'] as $key) {
                if ($key !== '' && !in_array($row['id'], $out[$key] ?? [], true)) {
                    $out[$key][] = $row['id'];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, list<int|string>> $lookup
     * @param list<string> $errors
     */
    private static function resolve(mixed $value, array $lookup, string $label, array &$errors, string $missing): int|string|null
    {
        $text = self::cellText($value);
        if ($text === '') {
            return null;
        }
        $found = $lookup[self::key($text)] ?? [];
        if (count($found) === 1) {
            return $found[0];
        }
        $errors[] = $label . ' "' . $text . '" ' . ($found !== [] ? 'matches more than one person' : $missing);

        return null;
    }
}
