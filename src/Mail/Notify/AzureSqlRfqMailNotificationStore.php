<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Notify;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;

/** `[mail].[rfq_notifications]` (database/scripts/development/create-mail-rfq-notifications.sql). */
final class AzureSqlRfqMailNotificationStore implements RfqMailNotificationStore
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function add(RfqMailNotification $n): void
    {
        // The unique (user, draft) index makes a repeat harmless; the NOT EXISTS keeps it from being an error.
        $this->connection->execute(
            'INSERT INTO [mail].[rfq_notifications] ([id], [user_id], [draft_public_id], [title], [body], [link], [created_at], [is_read])
             SELECT :id, :user, :draft, :title, :body, :link, :at, 0
              WHERE NOT EXISTS (SELECT 1 FROM [mail].[rfq_notifications] WHERE [user_id] = :user2 AND [draft_public_id] = :draft2)',
            [
                'id' => $n->id,
                'user' => $n->userId,
                'draft' => $n->draftPublicId,
                'title' => mb_substr($n->title, 0, 300),
                'body' => mb_substr($n->body, 0, 1000),
                'link' => mb_substr($n->link, 0, 500),
                'at' => AzureSqlValueMapper::formatDateTime($n->at),
                'user2' => $n->userId,
                'draft2' => $n->draftPublicId,
            ],
        );
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, min(200, $limit)) . ') [id], [user_id], [draft_public_id], [title], [body], [link], [created_at], [is_read]
               FROM [mail].[rfq_notifications] WHERE [user_id] = :user ORDER BY [created_at] DESC, [id] DESC',
            ['user' => $userId],
        );

        return array_map(static fn (array $row): RfqMailNotification => new RfqMailNotification(
            AzureSqlValueMapper::stringValue($row['id']),
            AzureSqlValueMapper::intValue($row['user_id']),
            AzureSqlValueMapper::stringValue($row['draft_public_id']),
            AzureSqlValueMapper::stringValue($row['title']),
            AzureSqlValueMapper::stringValue($row['body']),
            AzureSqlValueMapper::stringValue($row['link']),
            AzureSqlValueMapper::requireDateTime($row['created_at'], 'created_at'),
            AzureSqlValueMapper::boolValue($row['is_read']),
        ), $rows);
    }

    public function markAllRead(int $userId): void
    {
        $this->connection->execute('UPDATE [mail].[rfq_notifications] SET [is_read] = 1 WHERE [user_id] = :user AND [is_read] = 0', ['user' => $userId]);
    }
}
