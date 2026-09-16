<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class InMemoryExpenseAuditRecorder implements ExpenseAuditRecorder
{
    /** @var list<array{actorUserId: int, action: string, receiptPublicId: ?string, detail: ?string, createdAtUtc?: string}> */
    private array $events = [];

    public function record(
        UserId $actorUserId,
        string $action,
        ?ExpenseReceiptPublicId $receiptPublicId = null,
        ?string $detail = null,
    ): void {
        $this->events[] = [
            'actorUserId' => $actorUserId->toInt(),
            'action' => $action,
            'receiptPublicId' => $receiptPublicId?->toString(),
            'detail' => $detail,
            'createdAtUtc' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    public function listRecent(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $events = array_reverse($this->events);

        return array_slice($events, 0, $limit);
    }

    /**
     * @return list<array{actorUserId: int, action: string, receiptPublicId: ?string, detail: ?string, createdAtUtc?: string}>
     */
    public function events(): array
    {
        return $this->events;
    }
}
