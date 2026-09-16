<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface ExpenseAuditRecorder
{
    public function record(
        UserId $actorUserId,
        string $action,
        ?ExpenseReceiptPublicId $receiptPublicId = null,
        ?string $detail = null,
    ): void;

    /**
     * @return list<array{actorUserId: int, action: string, receiptPublicId: ?string, detail: ?string, createdAtUtc?: string}>
     */
    public function listRecent(int $limit = 200): array;
}