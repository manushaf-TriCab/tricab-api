<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Task\Contract\TaskReportRecipientStore;
use TriCab\Core\Finance\Task\Domain\TaskReportRecipient;

/** `finance.task_report_recipients`. The unique index on `email` (case-insensitive) settles a race. */
final class AzureSqlTaskReportRecipientStore extends AzureSqlTaskSupport implements TaskReportRecipientStore
{
    private const COLUMNS = '[id], [email], [name], [user_id], [created_date], [updated_date]';

    public function all(): array
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[task_report_recipients] ORDER BY [email], [id]');

        return array_map(self::recipient(...), $rows);
    }

    public function find(string $id): ?TaskReportRecipient
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[task_report_recipients] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::recipient($rows[0]);
    }

    public function findByEmail(string $email): ?TaskReportRecipient
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[task_report_recipients] WHERE [email] = :email', ['email' => $email]);

        return $rows === [] ? null : self::recipient($rows[0]);
    }

    public function insert(TaskReportRecipient $recipient): void
    {
        $this->write(
            'INSERT INTO [finance].[task_report_recipients] (' . self::COLUMNS . ') VALUES (:id, :email, :name, :user, :created, :updated)',
            [
                'id' => $recipient->id,
                'email' => $recipient->email,
                'name' => $recipient->name,
                'user' => $recipient->userId,
                'created' => self::at($recipient->createdDate),
                'updated' => self::at($recipient->updatedDate),
            ],
            'That address is already a recipient',
        );
    }

    public function delete(string $id): void
    {
        $this->connection->execute('DELETE FROM [finance].[task_report_recipients] WHERE [id] = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private static function recipient(array $row): TaskReportRecipient
    {
        return new TaskReportRecipient(
            self::id($row['id']),
            self::text($row['email']),
            self::text($row['name']),
            AzureSqlValueMapper::nullableInt($row['user_id']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }
}
