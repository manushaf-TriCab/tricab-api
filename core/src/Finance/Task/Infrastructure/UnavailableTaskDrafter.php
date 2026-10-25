<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Infrastructure;

use TriCab\Core\Finance\Task\Contract\TaskDrafter;
use TriCab\Core\Finance\Task\Domain\TaskDraftRequest;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;

/** The drafter when "Create with AI" is not switched on or Foundry is not set up: a plain 503, never an empty draft. */
final class UnavailableTaskDrafter implements TaskDrafter
{
    public function draft(TaskDraftRequest $request): array
    {
        throw new TaskUnavailable('Create with AI is not set up on this site yet. You can still create tasks and projects by hand.');
    }

    public function modelName(): string
    {
        return '';
    }
}
