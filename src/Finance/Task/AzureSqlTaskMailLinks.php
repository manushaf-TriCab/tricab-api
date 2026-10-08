<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use TriCab\Core\Finance\Task\Contract\TaskMailLinks;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;

/** `finance.task_mail_links`. No foreign key to the task or to the ledger (see the schema script). */
final class AzureSqlTaskMailLinks extends AzureSqlTaskSupport implements TaskMailLinks
{
    public function insert(TaskMailLink $link): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[task_mail_links] ([id], [kind], [task_id], [delivery_id], [created_at]) VALUES (:id, :kind, :task, :delivery, :created)',
            ['id' => $link->id, 'kind' => $link->kind, 'task' => $link->taskId, 'delivery' => $link->deliveryId, 'created' => self::at($link->createdAt)],
        );
    }

    public function forTask(string $taskId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [kind], [task_id], [delivery_id], [created_at] FROM [finance].[task_mail_links] WHERE [task_id] = :task ORDER BY [created_at] DESC, [id]',
            ['task' => $taskId],
        );

        return array_map(static fn (array $row): TaskMailLink => new TaskMailLink(
            self::id($row['id']),
            self::text($row['kind']),
            self::textOrNull($row['task_id']),
            self::id($row['delivery_id']),
            self::when($row['created_at'], 'created_at'),
        ), $rows);
    }
}
