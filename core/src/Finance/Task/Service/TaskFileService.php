<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use Throwable;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachment;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentInvalid;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentNotFound;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentRules;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Domain\TaskRecord;

/**
 * Files on tasks (Nick Han, 23 Oct 2026), in the shared finance file store under app `TASK` and record type
 * `task`: the bytes at `task/YYYY/MM/<id>` in the private finance container, the row in `finance.attachments`.
 *
 *  - Whoever can see a task (D15, a 404 otherwise) can list and download its files.
 *  - Whoever can edit it (D14, `UPDATE`, or `UPDATE_OWN` on their own task) can add and remove files.
 *  - A new task can have files before it is first saved: {@see uploadLoose()} keeps an unattached upload, the
 *    create call names it, and {@see attachUploads()} puts it on the task. Only the uploader may claim it, once;
 *    one never claimed is swept after a day by the shared job.
 *  - Binning keeps the files and restoring keeps them; {@see purge()} runs after a permanent delete.
 *
 * Limits: 20 MB a file (the host's, see MAX_BYTES), any type, no cap on how many. A file is read whole into memory both ways.
 */
final class TaskFileService
{
    public const RECORD_TYPE = 'task';
    /** Tri-Task's own ceiling per file: what the host's nginx and PHP let through (21 MB body, 20 MB upload), below the store's 32 MB. */
    public const MAX_BYTES = 20971520;
    public const TOO_BIG = 'The file is larger than 20 MB.';
    public const NOT_FOUND = 'File not found';
    public const NO_FILE = 'No file provided';
    public const UNKNOWN_UPLOAD = 'An upload was not found, or it is already on a task';

    public function __construct(
        private readonly FinanceAttachmentService $files,
        private readonly FinanceAttachmentStore $store,
        private readonly TaskStore $tasks,
        private readonly TaskFileGuard $guard,
        private readonly FinanceTaskMemberDirectory $members,
    ) {
    }

    /**
     * How many files each task has, in one query: what a task list shows as the paperclip and filters on.
     *
     * @return array<string, int> task id to count; a task with none is absent
     */
    public function counts(): array
    {
        return $this->store->countsByRecordType(Module::APP_TASK, self::RECORD_TYPE);
    }

    /** @return list<array<string, mixed>> the task's files, oldest first */
    public function list(TaskActor $actor, string $taskId): array
    {
        $this->visible($actor, $taskId);

        return $this->present($this->files->forRecord(Module::APP_TASK, self::RECORD_TYPE, $taskId));
    }

    /**
     * Adds a file to an existing task.
     *
     * @return array<string, mixed>
     */
    public function add(TaskActor $actor, string $taskId, ?string $name, string $contentType, string $bytes): array
    {
        $task = $this->visible($actor, $taskId);
        if (!$actor->mayEdit($task)) {
            throw new TaskForbidden('You do not have permission to change this task');
        }
        $file = $this->keep($actor, $name, $contentType, $bytes);
        try {
            $file = $this->files->attach(Module::APP_TASK, $file->id, self::RECORD_TYPE, $task->id, $actor->userId);
        } catch (Throwable $failure) {
            $this->files->remove(Module::APP_TASK, $file->id);

            throw $failure instanceof FinanceAttachmentNotFound ? new TaskNotFound(self::NOT_FOUND) : $failure;
        }

        return $this->present([$file])[0];
    }

    /**
     * Keeps a file for a task that is not saved yet. Anyone who may create tasks (the route's gate) may.
     *
     * @return array<string, mixed> the file as shown in a list, whose `id` is the upload id
     */
    public function uploadLoose(TaskActor $actor, ?string $name, string $contentType, string $bytes): array
    {
        return $this->present([$this->keep($actor, $name, $contentType, $bytes)])[0];
    }

    /**
     * Checks that every named upload is the caller's own and still unattached, before a task is created from them.
     *
     * @param list<string> $uploadIds
     */
    public function claimable(TaskActor $actor, array $uploadIds): void
    {
        foreach ($uploadIds as $id) {
            $found = $this->store->find(Module::APP_TASK, $id);
            if ($found === null || $found->isAttached() || $found->uploadedByUserId !== $actor->userId) {
                throw new TaskInvalid(self::UNKNOWN_UPLOAD);
            }
        }
    }

    /**
     * Puts the named uploads on the task just created. Run {@see claimable()} first.
     *
     * @param list<string> $uploadIds
     */
    public function attachUploads(TaskActor $actor, string $taskId, array $uploadIds): void
    {
        foreach ($uploadIds as $id) {
            try {
                $this->files->attach(Module::APP_TASK, $id, self::RECORD_TYPE, $taskId, $actor->userId);
            } catch (FinanceAttachmentNotFound) {
                throw new TaskInvalid(self::UNKNOWN_UPLOAD);
            }
        }
    }

    public function download(TaskActor $actor, string $taskId, string $fileId): TaskFile
    {
        $this->visible($actor, $taskId);
        $this->ownFile($taskId, $fileId);
        try {
            $file = $this->files->download(Module::APP_TASK, $fileId, $actor->userId, $this->guard);
        } catch (FinanceAttachmentNotFound) {
            throw new TaskNotFound(self::NOT_FOUND);
        }

        return new TaskFile($file->bytes, $file->attachment->contentType, $file->attachment->downloadName());
    }

    /** Takes one file off a task: its bytes, then its row. */
    public function remove(TaskActor $actor, string $taskId, string $fileId): void
    {
        $task = $this->visible($actor, $taskId);
        if (!$actor->mayEdit($task)) {
            throw new TaskForbidden('You do not have permission to change this task');
        }
        $this->ownFile($taskId, $fileId);
        $this->files->remove(Module::APP_TASK, $fileId);
    }

    /**
     * Deletes every file of tasks that were deleted for good: bytes first, then rows (the store's order). Runs after
     * the database change commits. May throw; the caller says files were left behind.
     *
     * @param list<string> $taskIds
     */
    public function purge(array $taskIds): void
    {
        foreach ($taskIds as $id) {
            $this->files->removeRecordFiles(Module::APP_TASK, self::RECORD_TYPE, $id);
        }
    }

    private function visible(TaskActor $actor, string $taskId): TaskRecord
    {
        $task = $this->tasks->findTask($taskId);
        if ($task === null || !$actor->maySee($task)) {
            throw new TaskNotFound('Task not found');
        }

        return $task;
    }

    /** The file must be on this very task: a file id from another task, or an unattached one, is not found here. */
    private function ownFile(string $taskId, string $fileId): FinanceAttachment
    {
        $found = $this->store->find(Module::APP_TASK, $fileId);
        if ($found === null || $found->recordType !== self::RECORD_TYPE || $found->recordId === null || strcasecmp($found->recordId, $taskId) !== 0) {
            throw new TaskNotFound(self::NOT_FOUND);
        }

        return $found;
    }

    private function keep(TaskActor $actor, ?string $name, string $contentType, string $bytes): FinanceAttachment
    {
        if ($name === null || trim($name) === '') {
            throw new TaskInvalid(self::NO_FILE);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new TaskInvalid(self::TOO_BIG);
        }
        try {
            $type = FinanceAttachmentRules::cleanContentType($contentType);
        } catch (FinanceAttachmentInvalid) {
            $type = FinanceAttachmentRules::DEFAULT_CONTENT_TYPE;
        }
        try {
            return $this->files->upload(Module::APP_TASK, $name, $type, $bytes, $actor->userId);
        } catch (FinanceAttachmentInvalid $invalid) {
            throw new TaskInvalid($invalid->getMessage());
        }
    }

    /**
     * @param list<FinanceAttachment> $files
     * @return list<array<string, mixed>>
     */
    private function present(array $files): array
    {
        $names = [];
        foreach ($this->members->members() as $member) {
            $names[$member->userId] = $member->name;
        }
        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'id' => $file->id,
                'name' => $file->originalName,
                'content_type' => $file->contentType,
                'size' => $file->byteSize,
                'uploaded_by_id' => TaskPresenter::person($file->uploadedByUserId),
                'uploaded_by_name' => $file->uploadedByUserId === null ? null : ($names[$file->uploadedByUserId] ?? null),
                'created_at' => TaskPresenter::iso($file->createdAt),
            ];
        }

        return $out;
    }
}
