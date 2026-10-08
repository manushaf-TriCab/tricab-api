<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

/**
 * One line of the import report while it is being built: a project or a task, what it would do
 * (`create`) or did (`created`), and why a row was refused. Internal to {@see TaskImportService}.
 *
 * @internal
 */
final class ImportLine
{
    public string $source = '';

    public string $project = '';

    public string $action = '';

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, mixed> what a `create` line will write; never in the report */
    public array $payload = [];

    public string $projectKey = '';

    public function __construct(public readonly int $row, public readonly string $name)
    {
    }

    /** @return array<string, mixed> */
    public function toProject(): array
    {
        return ['row' => $this->row, 'name' => $this->name, 'source' => $this->source, 'errors' => $this->errors, 'warnings' => $this->warnings, 'action' => $this->action];
    }

    /** @return array<string, mixed> */
    public function toTask(): array
    {
        return ['row' => $this->row, 'name' => $this->name, 'project' => $this->project, 'errors' => $this->errors, 'warnings' => $this->warnings, 'action' => $this->action];
    }
}
