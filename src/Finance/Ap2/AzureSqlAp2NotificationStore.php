<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2NotificationStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Notification;

/** `finance.ap2_notifications`. */
final class AzureSqlAp2NotificationStore extends AzureSqlAp2Support implements Ap2NotificationStore
{
    public function add(Ap2Notification $n): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ap2_notifications] ([id], [user_id], [title], [body], [at], [is_read], [link]) VALUES (:id, :user, :title, :body, :at, :read, :link)',
            ['id' => $n->id, 'user' => $n->userId, 'title' => mb_substr($n->title, 0, 300), 'body' => $n->body, 'at' => self::at($n->at), 'read' => $n->read ? 1 : 0, 'link' => mb_substr($n->link, 0, 500)],
        );
    }

    public function forUser(int $userId, int $limit = 100): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, min(500, $limit)) . ') [id], [user_id], [title], [body], [at], [is_read], [link]
               FROM [finance].[ap2_notifications] WHERE [user_id] = :user ORDER BY [at] DESC, [id] DESC',
            ['user' => $userId],
        );

        return array_map(static fn (array $row): Ap2Notification => new Ap2Notification(
            self::id($row['id']),
            self::int($row['user_id']),
            self::text($row['title']),
            self::text($row['body']),
            self::when($row['at'], 'at'),
            self::bool($row['is_read']),
            self::text($row['link']),
        ), $rows);
    }

    public function markAllRead(int $userId): void
    {
        $this->connection->execute('UPDATE [finance].[ap2_notifications] SET [is_read] = 1 WHERE [user_id] = :user AND [is_read] = 0', ['user' => $userId]);
    }
}
