<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use JsonException;
use TriCab\Core\Finance\Task\Contract\TaskCalendarInvites;
use TriCab\Core\Finance\Task\Domain\TaskCalendarInvite;

/** `finance.task_calendar_invites` (alter-finance-task-calendar-invites.sql): one row per task, the latest invite. */
final class AzureSqlTaskCalendarInvites extends AzureSqlTaskSupport implements TaskCalendarInvites
{
    private const COLUMNS = '[task_id], [uid], [sequence], [due_date_sent], [sent_by], [organizer_email], [recipients], [sent_at]';

    public function all(): array
    {
        $out = [];
        foreach ($this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[task_calendar_invites]') as $row) {
            $invite = self::invite($row);
            $out[$invite->taskId] = $invite;
        }

        return $out;
    }

    public function find(string $taskId): ?TaskCalendarInvite
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[task_calendar_invites] WHERE [task_id] = :task',
            ['task' => $taskId],
        );

        return $rows === [] ? null : self::invite($rows[0]);
    }

    public function save(TaskCalendarInvite $invite): void
    {
        $recipients = json_encode($invite->recipients, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->connection->execute(
            'MERGE [finance].[task_calendar_invites] WITH (HOLDLOCK) AS t
             USING (SELECT :task AS [task_id]) AS s ON t.[task_id] = s.[task_id]
             WHEN MATCHED THEN UPDATE SET [uid] = :uid, [sequence] = :seq, [due_date_sent] = :due, [sent_by] = :by,
                 [organizer_email] = :organizer, [recipients] = :recipients, [sent_at] = CAST(:at AS DATETIME2(6))
             WHEN NOT MATCHED THEN INSERT ([task_id], [uid], [sequence], [due_date_sent], [sent_by], [organizer_email], [recipients], [sent_at])
                 VALUES (s.[task_id], :uid2, :seq2, :due2, :by2, :organizer2, :recipients2, CAST(:at2 AS DATETIME2(6)));',
            [
                'task' => $invite->taskId,
                'uid' => $invite->uid, 'uid2' => $invite->uid,
                'seq' => $invite->sequence, 'seq2' => $invite->sequence,
                'due' => $invite->dueDateSent, 'due2' => $invite->dueDateSent,
                'by' => $invite->sentBy, 'by2' => $invite->sentBy,
                'organizer' => $invite->organizerEmail, 'organizer2' => $invite->organizerEmail,
                'recipients' => $recipients, 'recipients2' => $recipients,
                'at' => self::at($invite->sentAt), 'at2' => self::at($invite->sentAt),
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private static function invite(array $row): TaskCalendarInvite
    {
        $recipients = [];
        try {
            $decoded = json_decode(self::text($row['recipients']), true, 8, JSON_THROW_ON_ERROR);
            foreach (is_array($decoded) ? $decoded : [] as $address) {
                if (is_string($address)) {
                    $recipients[] = $address;
                }
            }
        } catch (JsonException) {
            // A damaged list reads as none: the invite itself still stands.
        }
        $by = $row['sent_by'] ?? null;

        return new TaskCalendarInvite(
            self::id($row['task_id']),
            self::text($row['uid']),
            (int) (is_numeric($row['sequence'] ?? null) ? $row['sequence'] : 0),
            self::text($row['due_date_sent']),
            is_numeric($by) ? (int) $by : null,
            self::text($row['organizer_email']),
            $recipients,
            self::when($row['sent_at'], 'sent_at'),
        );
    }
}
