<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Infrastructure\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Quotegen\Application\QuotegenBudget;
use TriCab\Api\Quotegen\Application\QuotegenBudgetLedger;

final readonly class AzureSqlQuotegenBudgetLedger implements QuotegenBudgetLedger
{
    public function __construct(private SqlConnection $connection)
    {
    }

    public function summary(string $rfqPublicId, int $limitMicros): QuotegenBudget
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    COALESCE(SUM(CASE WHEN [Kind] = N'actual' THEN [Micros] END), 0) AS [Spent],
    COALESCE(SUM(CASE WHEN [Kind] = N'reservation' THEN [Micros] END), 0) AS [Reserved]
FROM [rfq].[QuotegenBudgetLedger]
WHERE [RfqPublicId] = :rfqPublicId
SQL,
            [':rfqPublicId' => strtolower($rfqPublicId)],
        );

        return new QuotegenBudget(
            $limitMicros,
            AzureSqlValueMapper::intValue($rows[0]['Spent'] ?? 0),
            AzureSqlValueMapper::intValue($rows[0]['Reserved'] ?? 0),
        );
    }

    public function reserve(string $rfqPublicId, string $reservationId, int $micros, int $limitMicros): bool
    {
        // One statement: the check and the insert see the same ledger. Distinct
        // parameter names are intentional (pdo_sqlsrv rejects a reused one).
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [rfq].[QuotegenBudgetLedger] ([RfqPublicId], [ReservationId], [Kind], [Micros])
SELECT :rfqPublicId, :reservationId, N'reservation', :micros
WHERE (
    SELECT COALESCE(SUM([Micros]), 0)
    FROM [rfq].[QuotegenBudgetLedger] WITH (UPDLOCK, HOLDLOCK)
    WHERE [RfqPublicId] = :sumRfqPublicId
) + :sumMicros <= :limitMicros
SQL,
            [
                ':rfqPublicId' => strtolower($rfqPublicId),
                ':reservationId' => $reservationId,
                ':micros' => $micros,
                ':sumRfqPublicId' => strtolower($rfqPublicId),
                ':sumMicros' => $micros,
                ':limitMicros' => $limitMicros,
            ],
        );
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [Held] FROM [rfq].[QuotegenBudgetLedger] WHERE [ReservationId] = :reservationId',
            [':reservationId' => $reservationId],
        );

        return AzureSqlValueMapper::intValue($rows[0]['Held'] ?? 0) > 0;
    }

    public function reconcile(string $reservationId, int $actualMicros): void
    {
        $this->connection->execute(
            <<<'SQL'
UPDATE [rfq].[QuotegenBudgetLedger]
SET [Kind] = N'actual', [Micros] = :micros, [SettledAtUtc] = SYSUTCDATETIME()
WHERE [ReservationId] = :reservationId AND [Kind] = N'reservation'
SQL,
            [':micros' => max(0, $actualMicros), ':reservationId' => $reservationId],
        );
    }
}
