<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

use TriCab\Core\Finance\Task\Domain\TaskMailLink;

/** `finance.task_mail_links`: which ledger rows were sent about which task. */
interface TaskMailLinks
{
    public function insert(TaskMailLink $link): void;

    /** @return list<TaskMailLink> a task's links, newest first */
    public function forTask(string $taskId): array;
}
