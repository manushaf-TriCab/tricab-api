<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface ExpenseReminderLogRepository
{
    /**
     * @param ?string $reminderType one of Module::REMINDER_TYPE_*
     * @param ?string $idempotencyKey caller-owned dedupe key; unique when set
     * @return array{id: int, publicId: string, recipientEmail: string, sentAtUtc: string}
     */
    public function record(
        string $recipientEmail,
        ?UserId $recipientUserId,
        ?UserId $sentByUserId,
        DateTimeImmutable $sentAtUtc,
        ?string $legacySentBy = null,
        ?string $reminderType = null,
        ?string $idempotencyKey = null,
    ): array;

    /**
     * Has this exact reminder already been sent? Used by the scheduled monthly
     * digest so a re-run, a retried cron, or a manual kick cannot double-mail.
     */
    public function existsByIdempotencyKey(string $idempotencyKey): bool;

    /**
     * @return list<array{id: int, publicId: string, recipientEmail: string, recipientUserId: ?int, sentByUserId: ?int, sentAtUtc: string, reminderType?: ?string, idempotencyKey?: ?string}>
     */
    public function listRecent(int $limit = 100): array;
}
