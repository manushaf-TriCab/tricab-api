<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Domain\Entity\ExpensePeriod;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodStatus;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class AzureSqlExpensePeriodRepository implements ExpensePeriodRepository
{
    use AzureSqlTransaction;

    private const SELECT_COLUMNS = <<<'SQL'
    p.[ExpensePeriodId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[Country],
    CONVERT(VARCHAR(10), p.[PeriodStart], 23) AS [PeriodStart],
    CONVERT(VARCHAR(10), p.[PeriodEnd], 23) AS [PeriodEnd],
    CONVERT(VARCHAR(10), p.[TransactionStartDate], 23) AS [TransactionStartDate],
    CONVERT(VARCHAR(10), p.[TransactionEndDate], 23) AS [TransactionEndDate],
    p.[Status],
    CONVERT(VARCHAR(33), p.[ClosedAtUtc], 127) AS [ClosedAtUtc],
    p.[ClosedByUserId],
    CONVERT(VARCHAR(33), p.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    p.[LegacyPeriodId],
    CONVERT(VARBINARY(8), p.[RowVersion]) AS [RowVersion]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function save(ExpensePeriod $period, ?RowVersionToken $expectedRowVersion = null): ExpensePeriod
    {
        return $this->runInTransaction($this->connection, function () use ($period, $expectedRowVersion): ExpensePeriod {
            if ($period->id() === null) {
                return $this->insert($period);
            }

            return $this->update($period, $expectedRowVersion);
        });
    }

    public function findByPublicId(ExpensePeriodPublicId $publicId): ?ExpensePeriod
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpensePeriods] p
WHERE p.[PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findById(int $id): ?ExpensePeriod
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpensePeriods] p
WHERE p.[ExpensePeriodId] = :id',
            [':id' => $id],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findOpenByCountry(string $country): ?ExpensePeriod
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpensePeriods] p
WHERE p.[Country] = :country
  AND p.[Status] = N\'open\'
ORDER BY p.[PeriodEnd] DESC, p.[ExpensePeriodId] DESC',
            [':country' => strtoupper(trim($country))],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findLatestByCountry(string $country): ?ExpensePeriod
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpensePeriods] p
WHERE p.[Country] = :country
ORDER BY p.[PeriodEnd] DESC, p.[ExpensePeriodId] DESC',
            [':country' => strtoupper(trim($country))],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function listByCountry(?string $country = null): array
    {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpensePeriods] p';
        $params = [];
        if ($country !== null && trim($country) !== '') {
            $sql .= ' WHERE p.[Country] = :country';
            $params[':country'] = strtoupper(trim($country));
        }
        $sql .= ' ORDER BY p.[PeriodEnd] DESC, p.[ExpensePeriodId] DESC';

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): ExpensePeriod => $this->hydrate($row), $rows);
    }

    private function insert(ExpensePeriod $period): ExpensePeriod
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [expense].[ExpensePeriods] (
    [PublicId],
    [Country],
    [PeriodStart],
    [PeriodEnd],
    [TransactionStartDate],
    [TransactionEndDate],
    [Status],
    [ClosedAtUtc],
    [ClosedByUserId],
    [CreatedAtUtc],
    [LegacyPeriodId]
)
OUTPUT
    INSERTED.[ExpensePeriodId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[Country],
    CONVERT(VARCHAR(10), INSERTED.[PeriodStart], 23) AS [PeriodStart],
    CONVERT(VARCHAR(10), INSERTED.[PeriodEnd], 23) AS [PeriodEnd],
    CONVERT(VARCHAR(10), INSERTED.[TransactionStartDate], 23) AS [TransactionStartDate],
    CONVERT(VARCHAR(10), INSERTED.[TransactionEndDate], 23) AS [TransactionEndDate],
    INSERTED.[Status],
    CONVERT(VARCHAR(33), INSERTED.[ClosedAtUtc], 127) AS [ClosedAtUtc],
    INSERTED.[ClosedByUserId],
    CONVERT(VARCHAR(33), INSERTED.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    INSERTED.[LegacyPeriodId],
    CONVERT(VARBINARY(8), INSERTED.[RowVersion]) AS [RowVersion]
VALUES (
    :publicId,
    :country,
    :periodStart,
    :periodEnd,
    :transactionStartDate,
    :transactionEndDate,
    :status,
    :closedAtUtc,
    :closedByUserId,
    :createdAtUtc,
    :legacyPeriodId
)
SQL,
            $this->writeParams($period),
        );

        return $this->hydrate($rows[0]);
    }

    private function update(ExpensePeriod $period, ?RowVersionToken $expectedRowVersion): ExpensePeriod
    {
        $params = $this->writeParams($period);
        $params[':expensePeriodId'] = $period->id();
        unset($params[':publicId'], $params[':createdAtUtc']);

        $sql = <<<'SQL'
UPDATE [expense].[ExpensePeriods]
SET
    [Country] = :country,
    [PeriodStart] = :periodStart,
    [PeriodEnd] = :periodEnd,
    [TransactionStartDate] = :transactionStartDate,
    [TransactionEndDate] = :transactionEndDate,
    [Status] = :status,
    [ClosedAtUtc] = :closedAtUtc,
    [ClosedByUserId] = :closedByUserId,
    [LegacyPeriodId] = :legacyPeriodId
OUTPUT
    INSERTED.[ExpensePeriodId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[Country],
    CONVERT(VARCHAR(10), INSERTED.[PeriodStart], 23) AS [PeriodStart],
    CONVERT(VARCHAR(10), INSERTED.[PeriodEnd], 23) AS [PeriodEnd],
    CONVERT(VARCHAR(10), INSERTED.[TransactionStartDate], 23) AS [TransactionStartDate],
    CONVERT(VARCHAR(10), INSERTED.[TransactionEndDate], 23) AS [TransactionEndDate],
    INSERTED.[Status],
    CONVERT(VARCHAR(33), INSERTED.[ClosedAtUtc], 127) AS [ClosedAtUtc],
    INSERTED.[ClosedByUserId],
    CONVERT(VARCHAR(33), INSERTED.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    INSERTED.[LegacyPeriodId],
    CONVERT(VARBINARY(8), INSERTED.[RowVersion]) AS [RowVersion]
WHERE [ExpensePeriodId] = :expensePeriodId
SQL;

        if ($expectedRowVersion !== null) {
            $sql .= ' AND [RowVersion] = :expectedRowVersion';
            $params[':expectedRowVersion'] = ProductRowVersionMapper::toSqlBinary($expectedRowVersion);
        }

        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            throw ExpenseConcurrencyConflict::forPeriod();
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(ExpensePeriod $period): array
    {
        return [
            ':publicId' => $period->publicId()->toString(),
            ':country' => $period->country(),
            ':periodStart' => $period->periodStart()->format('Y-m-d'),
            ':periodEnd' => $period->periodEnd()->format('Y-m-d'),
            ':transactionStartDate' => $period->transactionStartDate()?->format('Y-m-d'),
            ':transactionEndDate' => $period->transactionEndDate()?->format('Y-m-d'),
            ':status' => $period->status()->toString(),
            ':closedAtUtc' => $period->closedAt() !== null
                ? AzureSqlValueMapper::formatDateTime($period->closedAt())
                : null,
            ':closedByUserId' => $period->closedByUserId()?->toInt(),
            ':createdAtUtc' => AzureSqlValueMapper::formatDateTime($period->createdAtUtc()),
            ':legacyPeriodId' => $period->legacyPeriodId(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ExpensePeriod
    {
        $closedBy = AzureSqlValueMapper::nullableInt($row['ClosedByUserId'] ?? null);

        return ExpensePeriod::reconstitute(
            id: AzureSqlValueMapper::intValue($row['ExpensePeriodId'] ?? 0),
            publicId: ExpensePeriodPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            country: AzureSqlValueMapper::stringValue($row['Country'] ?? ''),
            periodStart: $this->requireDate($row['PeriodStart'] ?? null, 'PeriodStart'),
            periodEnd: $this->requireDate($row['PeriodEnd'] ?? null, 'PeriodEnd'),
            transactionStartDate: $this->nullableDate($row['TransactionStartDate'] ?? null),
            transactionEndDate: $this->nullableDate($row['TransactionEndDate'] ?? null),
            status: ExpensePeriodStatus::fromString(AzureSqlValueMapper::stringValue($row['Status'] ?? '')),
            closedAt: AzureSqlValueMapper::nullableDateTime($row['ClosedAtUtc'] ?? null),
            closedByUserId: $closedBy !== null ? UserId::fromInt($closedBy) : null,
            createdAtUtc: AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            legacyPeriodId: AzureSqlValueMapper::nullableInt($row['LegacyPeriodId'] ?? null),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
        );
    }

    private function requireDate(mixed $value, string $field): DateTimeImmutable
    {
        $parsed = $this->nullableDate($value);
        if ($parsed === null) {
            throw new \RuntimeException(sprintf('Missing or invalid date for %s.', $field));
        }

        return $parsed;
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        $raw = AzureSqlValueMapper::nullableString($value);
        if ($raw === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $raw);

        return $parsed instanceof DateTimeImmutable ? $parsed : new DateTimeImmutable($raw);
    }
}
