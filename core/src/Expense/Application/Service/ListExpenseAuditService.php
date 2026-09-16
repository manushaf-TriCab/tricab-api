<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;

final class ListExpenseAuditService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseAuditRecorder $audit,
    ) {
    }

    /**
     * @return list<array{actorUserId: int, action: string, receiptPublicId: ?string, detail: ?string, createdAtUtc?: string}>
     */
    public function execute(ExpenseActor $actor, ?int $limit = null): array
    {
        $this->access->requireAudit($actor);

        return $this->audit->listRecent($limit ?? 200);
    }
}
