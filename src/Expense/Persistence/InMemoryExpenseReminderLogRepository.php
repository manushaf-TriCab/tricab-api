<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use DateTimeImmutable;
use TriCab\Core\Expense\Contract\ExpenseReminderLogRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class InMemoryExpenseReminderLogRepository implements ExpenseReminderLogRepository
{
    /** @var list<array{id: int, publicId: string, recipientEmail: string, recipientUserId: ?int, sentByUserId: ?int, sentAtUtc: string}> */
    private array $rows = [];

    private int $nextId = 1;

    public function record(
        string $recipientEmail,
        ?UserId $recipientUserId,
        ?UserId $sentByUserId,
        DateTimeImmutable $sentAtUtc,
        ?string $legacySentBy = null,
    ): array {
        unset($legacySentBy);
        $id = $this->nextId++;
        $publicId = ExpenseReceiptPublicId::generate()->toString();
        $row = [
            'id' => $id,
            'publicId' => $publicId,
            'recipientEmail' => strtolower(trim($recipientEmail)),
            'recipientUserId' => $recipientUserId?->toInt(),
            'sentByUserId' => $sentByUserId?->toInt(),
            'sentAtUtc' => $sentAtUtc->format(DATE_ATOM),
        ];
        $this->rows[] = $row;

        return [
            'id' => $row['id'],
            'publicId' => $row['publicId'],
            'recipientEmail' => $row['recipientEmail'],
            'sentAtUtc' => $row['sentAtUtc'],
        ];
    }

    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));

        return array_slice(array_reverse($this->rows), 0, $limit);
    }
}
