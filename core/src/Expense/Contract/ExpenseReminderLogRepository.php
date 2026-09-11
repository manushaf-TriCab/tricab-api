<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface ExpenseReminderLogRepository
{
    /**
     * @return array{id: int, publicId: string, recipientEmail: string, sentAtUtc: string}
     */
    public function record(
        string $recipientEmail,
        ?UserId $recipientUserId,
        ?UserId $sentByUserId,
        DateTimeImmutable $sentAtUtc,
        ?string $legacySentBy = null,
    ): array;

    /**
     * @return list<array{id: int, publicId: string, recipientEmail: string, recipientUserId: ?int, sentByUserId: ?int, sentAtUtc: string}>
     */
    public function listRecent(int $limit = 100): array;
}
