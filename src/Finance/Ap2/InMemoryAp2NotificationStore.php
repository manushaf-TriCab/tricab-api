<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2NotificationStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Notification;

/** The bell held in memory. */
final class InMemoryAp2NotificationStore implements Ap2NotificationStore
{
    /** @var list<Ap2Notification> */
    private array $rows = [];

    public function add(Ap2Notification $notification): void
    {
        $this->rows[] = $notification;
    }

    public function forUser(int $userId, int $limit = 100): array
    {
        $mine = array_values(array_filter($this->rows, static fn (Ap2Notification $n): bool => $n->userId === $userId));

        return array_slice(array_reverse($mine), 0, $limit);
    }

    public function markAllRead(int $userId): void
    {
        foreach ($this->rows as $i => $n) {
            if ($n->userId === $userId && !$n->read) {
                $this->rows[$i] = new Ap2Notification($n->id, $n->userId, $n->title, $n->body, $n->at, true, $n->link);
            }
        }
    }
}
