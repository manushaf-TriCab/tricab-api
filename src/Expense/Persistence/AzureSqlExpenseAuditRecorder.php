<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class AzureSqlExpenseAuditRecorder implements ExpenseAuditRecorder
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function record(
        UserId $actorUserId,
        string $action,
        ExpenseReceiptPublicId $receiptPublicId,
        ?string $detail = null,
    ): void {
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [expense].[ExpenseAuditEvents] (
    [ActorUserId],
    [ActionCode],
    [ExpenseReceiptPublicId],
    [Detail],
    [CreatedAtUtc]
)
VALUES (
    :actorUserId,
    :actionCode,
    :receiptPublicId,
    :detail,
    SYSUTCDATETIME()
)
SQL,
            [
                ':actorUserId' => $actorUserId->toInt(),
                ':actionCode' => trim($action),
                ':receiptPublicId' => $receiptPublicId->toString(),
                ':detail' => $detail,
            ],
        );
    }
}
