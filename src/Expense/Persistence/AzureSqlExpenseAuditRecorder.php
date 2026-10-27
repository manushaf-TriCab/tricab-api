<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Contract\ExpenseAuditFilter;
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
        ?ExpenseReceiptPublicId $receiptPublicId = null,
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
                ':receiptPublicId' => $receiptPublicId?->toString(),
                ':detail' => $detail,
            ],
        );
    }

    public function listRecent(int $limit = 200, ?ExpenseAuditFilter $filter = null): array
    {
        $limit = max(1, min(1000, $limit));
        $where = [];
        $params = [];
        if ($filter !== null) {
            if (($from = $filter->fromBoundary()) !== null) {
                $where[] = 'e.[CreatedAtUtc] >= :fromUtc';
                $params[':fromUtc'] = AzureSqlValueMapper::formatDateTime($from);
            }
            if (($to = $filter->toExclusiveBoundary()) !== null) {
                $where[] = 'e.[CreatedAtUtc] < :toUtc';
                $params[':toUtc'] = AzureSqlValueMapper::formatDateTime($to);
            }
            if ($filter->action !== null) {
                $where[] = 'e.[ActionCode] = :actionCode';
                $params[':actionCode'] = $filter->action;
            }
            if ($filter->actorUserId !== null) {
                $where[] = 'e.[ActorUserId] = :actorUserId';
                $params[':actorUserId'] = $filter->actorUserId;
            }
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT TOP ({$limit})
    e.[ActorUserId],
    e.[ActionCode],
    CONVERT(NVARCHAR(36), e.[ExpenseReceiptPublicId]) AS [ExpenseReceiptPublicId],
    e.[Detail],
    CONVERT(VARCHAR(33), e.[CreatedAtUtc], 127) AS [CreatedAtUtc]
FROM [expense].[ExpenseAuditEvents] e
{$whereSql}
ORDER BY e.[ExpenseAuditEventId] DESC
SQL,
            $params,
        );

        return array_map(static function (array $row): array {
            return [
                'actorUserId' => AzureSqlValueMapper::intValue($row['ActorUserId'] ?? 0),
                'action' => AzureSqlValueMapper::stringValue($row['ActionCode'] ?? ''),
                'receiptPublicId' => AzureSqlValueMapper::nullableString($row['ExpenseReceiptPublicId'] ?? null),
                'detail' => AzureSqlValueMapper::nullableString($row['Detail'] ?? null),
                'createdAtUtc' => AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
            ];
        }, $rows);
    }
}
