<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

use TriCab\Core\Finance\Task\Domain\TaskDraftRequest;
use TriCab\Core\Finance\Task\Domain\TaskFailure;

/**
 * The model behind "Create with AI": reads a person's summary and proposes a project and tasks.
 *
 * What it returns is the model's raw proposal and is **not trusted**: {@see \TriCab\Core\Finance\Task\Service\TaskDraftService}
 * checks every field against the lists the request carried and drops what is not in them.
 */
interface TaskDrafter
{
    /**
     * The raw proposal: `{project?: {...}, tasks: [{...}], injection_suspected?: bool}`.
     *
     * @return array<string, mixed>
     * @throws TaskFailure when no usable answer could be had (not set up, unreachable, refused, invalid output)
     */
    public function draft(TaskDraftRequest $request): array;

    /** The deployment that answers, for the log and the response; '' when none. */
    public function modelName(): string;
}
