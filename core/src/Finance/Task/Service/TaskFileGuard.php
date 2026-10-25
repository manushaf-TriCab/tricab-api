<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceRecordGuard;
use TriCab\Core\Finance\Task\Contract\TaskStore;

/**
 * Tri-Task's answer to "may this caller see that record?" for the shared file store. A binned task still has
 * its files (they go only when the task is deleted for good), so the task only has to exist. Who may see which
 * task (D15) is checked by {@see TaskFileService} before it asks the store; this answers for the app's own record
 * type only.
 */
final class TaskFileGuard implements FinanceRecordGuard
{
    public function __construct(private readonly TaskStore $tasks)
    {
    }

    public function maySee(string $application, string $recordType, string $recordId): bool
    {
        return $application === Module::APP_TASK
            && $recordType === TaskFileService::RECORD_TYPE
            && $this->tasks->findTask($recordId) !== null;
    }
}
