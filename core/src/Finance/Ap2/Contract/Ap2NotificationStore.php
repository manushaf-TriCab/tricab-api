<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2Notification;

/** `finance.ap2_notifications`: a person's bell. */
interface Ap2NotificationStore
{
    public function add(Ap2Notification $notification): void;

    /** @return list<Ap2Notification> the person's, newest first, at most `$limit` */
    public function forUser(int $userId, int $limit = 100): array;

    public function markAllRead(int $userId): void;
}
