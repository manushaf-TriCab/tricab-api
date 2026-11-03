<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Notify;

final class InMemoryRfqMailNotificationStore implements RfqMailNotificationStore
{
    /** @var list<RfqMailNotification> */
    private array $rows = [];

    public function add(RfqMailNotification $notification): void
    {
        foreach ($this->rows as $row) {
            if ($row->userId === $notification->userId && $row->draftPublicId === $notification->draftPublicId) {
                return;
            }
        }
        $this->rows[] = $notification;
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        $mine = array_values(array_filter($this->rows, static fn (RfqMailNotification $n): bool => $n->userId === $userId));
        usort($mine, static fn (RfqMailNotification $a, RfqMailNotification $b): int => $b->at <=> $a->at);

        return array_slice($mine, 0, max(1, $limit));
    }

    public function markAllRead(int $userId): void
    {
        foreach ($this->rows as $index => $row) {
            if ($row->userId === $userId && !$row->read) {
                $this->rows[$index] = new RfqMailNotification($row->id, $row->userId, $row->draftPublicId, $row->title, $row->body, $row->link, $row->at, true);
            }
        }
    }
}
