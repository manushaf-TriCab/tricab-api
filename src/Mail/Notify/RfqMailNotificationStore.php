<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Notify;

/** `[mail].[rfq_notifications]`: one row per person per draft. */
interface RfqMailNotificationStore
{
    /** Adds the notice unless that person already has one for that draft. */
    public function add(RfqMailNotification $notification): void;

    /** @return list<RfqMailNotification> newest first */
    public function forUser(int $userId, int $limit = 50): array;

    public function markAllRead(int $userId): void;
}
