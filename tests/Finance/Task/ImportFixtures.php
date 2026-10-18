<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

/**
 * The import template's shape, built in the test (never the shipped template, whose rows name real
 * entities): the two sheets' headings, and rows keyed by heading.
 */
final class ImportFixtures
{
    public const PROJECT_HEAD = ['Project name', 'Description', 'Status', 'Target Date'];

    public const TASK_HEAD = [
        'Entity', 'Task Name', 'Project', 'Description', 'Requirements', 'Notes', 'Finance Statement Area',
        'Category', 'Status', 'Preparer', 'Reviewer', 'Progress %', 'Due Date', 'Due Date Requested', 'Mark as Urgent',
    ];

    /** The export's headings since 16 Oct 2026: Category is Type, and Priority, Group and Task ID were added. */
    public const EXPORT_HEAD = [
        'Entity', 'Task Name', 'Project', 'Group', 'Description', 'Requirements', 'Notes', 'Finance Statement Area',
        'Type', 'Status', 'Priority', 'Preparer', 'Reviewer', 'Progress %', 'Due Date', 'Due Date Requested', 'Mark as Urgent', 'Task ID',
    ];

    /**
     * @param array<string, mixed> $cells
     * @param list<string> $head
     * @return list<mixed>
     */
    public static function task(string $name = 'Bank rec', string $project = 'Month End', array $cells = [], array $head = self::TASK_HEAD): array
    {
        $row = $cells + [
            'Entity' => 'Example Trading', 'Task Name' => $name, 'Project' => $project, 'Category' => 'routine', 'Type' => 'routine',
            'Status' => 'in_progress', 'Preparer' => 'Una User', 'Reviewer' => 'Mo Manager', 'Progress %' => 0,
            'Due Date Requested' => 'No', 'Mark as Urgent' => 'No',
        ];

        return array_map(static fn (string $heading): mixed => $row[$heading] ?? null, $head);
    }

    /**
     * The workbook's two sheets as raw rows.
     *
     * @param list<list<mixed>> $projects
     * @param list<list<mixed>> $tasks
     * @param list<string> $taskHead
     * @return array{0: list<list<mixed>>, 1: list<list<mixed>>}
     */
    public static function sheets(array $projects = [], array $tasks = [], array $taskHead = self::TASK_HEAD): array
    {
        return [[self::PROJECT_HEAD, ...$projects], [$taskHead, ...$tasks]];
    }
}
