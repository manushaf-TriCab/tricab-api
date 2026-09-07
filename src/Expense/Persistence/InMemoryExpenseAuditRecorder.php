<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class InMemoryExpenseAuditRecorder implements ExpenseAuditRecorder
{
    /** @var list<array{actor:int,action:string,receipt:string,detail:?string}> */
    private array $events = [];

    public function record(
        UserId $actorUserId,
        string $action,
        ExpenseReceiptPublicId $receiptPublicId,
        ?string $detail = null,
    ): void {
        $this->events[] = [
            'actor' => $actorUserId->toInt(),
            'action' => $action,
            'receipt' => $receiptPublicId->toString(),
            'detail' => $detail,
        ];
    }

    /** @return list<array{actor:int,action:string,receipt:string,detail:?string}> */
    public function events(): array
    {
        return $this->events;
    }
}