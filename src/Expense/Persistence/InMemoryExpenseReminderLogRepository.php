<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use DateTimeImmutable;
use TriCab\Core\Expense\Contract\ExpenseReminderLogRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class InMemoryExpenseReminderLogRepository implements ExpenseReminderLogRepository
{
    /** @var list<array{id: int, publicId: string, recipientEmail: string, recipientUserId: ?int, sentByUserId: ?int, sentAtUtc: string, reminderType: ?string, idempotencyKey: ?string}> */
    private array $rows = [];

    private int $nextId = 1;

    public function record(
        string $recipientEmail,
        ?UserId $recipientUserId,
        ?UserId $sentByUserId,
        DateTimeImmutable $sentAtUtc,
        ?string $legacySentBy = null,
        ?string $reminderType = null,
        ?string $idempotencyKey = null,
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
            'reminderType' => $reminderType,
            'idempotencyKey' => $idempotencyKey,
        ];
        $this->rows[] = $row;

        return [
            'id' => $row['id'],
            'publicId' => $row['publicId'],
            'recipientEmail' => $row['recipientEmail'],
            'sentAtUtc' => $row['sentAtUtc'],
        ];
    }

    public function existsByIdempotencyKey(string $idempotencyKey): bool
    {
        $key = trim($idempotencyKey);
        if ($key === '') {
            return false;
        }

        foreach ($this->rows as $row) {
            if ($row['idempotencyKey'] === $key) {
                return true;
            }
        }

        return false;
    }

    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));

        return array_slice(array_reverse($this->rows), 0, $limit);
    }
}
