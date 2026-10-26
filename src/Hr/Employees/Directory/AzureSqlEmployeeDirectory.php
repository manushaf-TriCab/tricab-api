<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;

/**
 * The `[hr]` mirror on Azure SQL. The base queries never touch
 * `[hr].[EmployeeRestrictedDetails]`; only {@see self::restricted()} does.
 */
final class AzureSqlEmployeeDirectory implements EmployeeDirectory
{
    /**
     * The manager is the employee whose normalised login matches ManagerLogin
     * (a current one first when case-variant duplicates exist); no match leaves
     * the manager columns NULL, which is how a dangling manager shows.
     */
    private const SELECT = <<<'SQL'
        SELECT
            e.[EmployeeCode], e.[UserLogin], e.[FirstName], e.[LastName], e.[DisplayName],
            e.[DepartmentRaw], e.[FactoryRaw], e.[WorkEmail], e.[ManagerLogin],
            COALESCE(NULLIF(LTRIM(RTRIM(m.[DisplayName])), N''), NULLIF(LTRIM(RTRIM(CONCAT(m.[FirstName], N' ', m.[LastName]))), N'')) AS [ManagerName],
            m.[EmployeeCode] AS [ManagerCode],
            CONVERT(VARCHAR(10), e.[DateHired], 23) AS [DateHired],
            e.[IsCurrent],
            CONVERT(VARCHAR(10), e.[LegacyUpdateDate], 23) AS [LastSeenInPayroll],
            CONVERT(VARCHAR(33), e.[MissingFromSourceAt], 127) AS [MissingFromSourceAt],
            CONVERT(NVARCHAR(36), u.[PublicId]) AS [LinkedUserPublicId],
            u.[DisplayName] AS [LinkedUserDisplayName]
        FROM [hr].[Employees] e
        OUTER APPLY (
            SELECT TOP 1 x.[EmployeeCode], x.[DisplayName], x.[FirstName], x.[LastName]
            FROM [hr].[Employees] x
            WHERE e.[ManagerLogin] IS NOT NULL
              AND x.[NormalizedUserLogin] = LOWER(LTRIM(RTRIM(e.[ManagerLogin])))
            ORDER BY x.[IsCurrent] DESC, x.[EmployeeCode]
        ) m
        LEFT JOIN [identity].[Users] u ON u.[UserId] = e.[UserId]
        SQL;

    private const ORDER = [
        EmployeeListCriteria::SORT_NAME => "COALESCE(NULLIF(LTRIM(RTRIM(e.[DisplayName])), N''), e.[EmployeeCode])",
        EmployeeListCriteria::SORT_CODE => 'e.[EmployeeCode]',
        EmployeeListCriteria::SORT_DEPARTMENT => 'e.[DepartmentRaw]',
        EmployeeListCriteria::SORT_FACTORY => 'e.[FactoryRaw]',
        EmployeeListCriteria::SORT_MANAGER => 'e.[ManagerLogin]',
        EmployeeListCriteria::SORT_DATE_HIRED => 'e.[DateHired]',
        EmployeeListCriteria::SORT_LAST_SEEN => 'e.[LegacyUpdateDate]',
    ];

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function search(EmployeeListCriteria $criteria): array
    {
        [$where, $params] = $this->filter($criteria);
        $direction = $criteria->descending() ? 'DESC' : 'ASC';
        // The code breaks ties, so a page boundary never repeats or drops a row.
        $order = self::ORDER[$criteria->sortBy] . ' ' . $direction
            . ($criteria->sortBy === EmployeeListCriteria::SORT_CODE ? '' : ', e.[EmployeeCode]');

        $rows = $this->connection->fetchAll(
            self::SELECT . <<<SQL

            {$where}
            ORDER BY {$order}
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
            SQL,
            [...$params, ':offset' => ($criteria->page - 1) * $criteria->pageSize, ':pageSize' => $criteria->pageSize],
        );

        $total = $this->connection->fetchAll('SELECT COUNT(*) AS [Total] FROM [hr].[Employees] e ' . $where, $params);

        return [
            'items' => array_map(self::item(...), $rows),
            'total' => AzureSqlValueMapper::intValue($total[0]['Total'] ?? 0),
        ];
    }

    public function facets(string $status): array
    {
        $where = match ($status) {
            EmployeeListCriteria::STATUS_CURRENT => 'WHERE [IsCurrent] = 1',
            EmployeeListCriteria::STATUS_PAST => 'WHERE [IsCurrent] = 0',
            default => '',
        };

        return [
            'departments' => $this->facet('DepartmentRaw', $where),
            'factories' => $this->facet('FactoryRaw', $where),
        ];
    }

    public function find(string $code): ?array
    {
        $rows = $this->connection->fetchAll(self::SELECT . ' WHERE e.[EmployeeCode] = :code', [':code' => $code]);

        return $rows === [] ? null : self::item($rows[0]);
    }

    public function findByLogin(string $login): ?array
    {
        $rows = $this->connection->fetchAll(
            self::SELECT . ' WHERE e.[NormalizedUserLogin] = :login ORDER BY e.[IsCurrent] DESC, e.[EmployeeCode] OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY',
            [':login' => strtolower(trim($login))],
        );

        return $rows === [] ? null : self::item($rows[0]);
    }

    public function directReports(string $login): array
    {
        $rows = $this->connection->fetchAll(
            self::SELECT . ' WHERE e.[IsCurrent] = 1 AND LOWER(LTRIM(RTRIM(e.[ManagerLogin]))) = :login'
            . " ORDER BY COALESCE(NULLIF(LTRIM(RTRIM(e.[DisplayName])), N''), e.[EmployeeCode]), e.[EmployeeCode]",
            [':login' => strtolower(trim($login))],
        );

        return array_map(self::item(...), $rows);
    }

    public function restricted(string $code): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT [PersonalEmail], [Mobile], [Street], [Suburb], [State], [Postcode],
                   CONVERT(VARCHAR(10), [DateOfBirth], 23) AS [DateOfBirth],
                   [AnnualLeaveRemaining], [LongServiceLeaveRemaining], [SickLeaveRemaining],
                   CONVERT(VARCHAR(10), [LeaveAsOf], 23) AS [LeaveAsOf]
            FROM [hr].[EmployeeRestrictedDetails]
            WHERE [EmployeeCode] = :code
            SQL,
            [':code' => $code],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return [
            'personalEmail' => AzureSqlValueMapper::nullableString($row['PersonalEmail'] ?? null),
            'mobile' => AzureSqlValueMapper::nullableString($row['Mobile'] ?? null),
            'street' => AzureSqlValueMapper::nullableString($row['Street'] ?? null),
            'suburb' => AzureSqlValueMapper::nullableString($row['Suburb'] ?? null),
            'state' => AzureSqlValueMapper::nullableString($row['State'] ?? null),
            'postcode' => AzureSqlValueMapper::nullableString($row['Postcode'] ?? null),
            'dateOfBirth' => AzureSqlValueMapper::nullableString($row['DateOfBirth'] ?? null),
            'annualLeaveRemaining' => self::decimal($row['AnnualLeaveRemaining'] ?? null),
            'longServiceLeaveRemaining' => self::decimal($row['LongServiceLeaveRemaining'] ?? null),
            'sickLeaveRemaining' => self::decimal($row['SickLeaveRemaining'] ?? null),
            'leaveAsOf' => AzureSqlValueMapper::nullableString($row['LeaveAsOf'] ?? null),
        ];
    }

    public function syncRuns(int $limit): array
    {
        $top = max(1, $limit);
        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT TOP ({$top})
                [SyncRunId], CONVERT(VARCHAR(33), [StartedAt], 127) AS [StartedAt],
                CONVERT(VARCHAR(33), [FinishedAt], 127) AS [FinishedAt],
                [Mode], [Status], [RowsRead], [RowsInserted], [RowsUpdated], [RowsUnchanged], [RowsMissing],
                [CurrentCount], CONVERT(VARCHAR(10), [LatestPayrollDate], 23) AS [LatestPayrollDate],
                [UnmatchedLogins], [Warning], [Error]
            FROM [hr].[SyncRuns]
            ORDER BY [StartedAt] DESC, [SyncRunId] DESC
            SQL,
        );

        return array_map(static fn (array $row): array => [
            'id' => AzureSqlValueMapper::intValue($row['SyncRunId'] ?? 0),
            'startedAt' => AzureSqlValueMapper::nullableString($row['StartedAt'] ?? null),
            'finishedAt' => AzureSqlValueMapper::nullableString($row['FinishedAt'] ?? null),
            'mode' => AzureSqlValueMapper::stringValue($row['Mode'] ?? ''),
            'status' => AzureSqlValueMapper::stringValue($row['Status'] ?? ''),
            'rowsRead' => AzureSqlValueMapper::intValue($row['RowsRead'] ?? 0),
            'rowsInserted' => AzureSqlValueMapper::intValue($row['RowsInserted'] ?? 0),
            'rowsUpdated' => AzureSqlValueMapper::intValue($row['RowsUpdated'] ?? 0),
            'rowsUnchanged' => AzureSqlValueMapper::intValue($row['RowsUnchanged'] ?? 0),
            'rowsMissing' => AzureSqlValueMapper::intValue($row['RowsMissing'] ?? 0),
            'currentCount' => AzureSqlValueMapper::nullableInt($row['CurrentCount'] ?? null),
            'latestPayrollDate' => AzureSqlValueMapper::nullableString($row['LatestPayrollDate'] ?? null),
            'unmatchedLogins' => AzureSqlValueMapper::nullableInt($row['UnmatchedLogins'] ?? null),
            'warning' => AzureSqlValueMapper::nullableString($row['Warning'] ?? null),
            'error' => AzureSqlValueMapper::nullableString($row['Error'] ?? null),
        ], $rows);
    }

    public function totals(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT COALESCE(SUM(CASE WHEN [IsCurrent] = 1 THEN 1 ELSE 0 END), 0) AS [CurrentCount],
                   CONVERT(VARCHAR(10), MAX([LegacyUpdateDate]), 23) AS [LatestPayrollDate]
            FROM [hr].[Employees]
            SQL,
        );

        return [
            'latestPayrollDate' => AzureSqlValueMapper::nullableString($rows[0]['LatestPayrollDate'] ?? null),
            'currentCount' => AzureSqlValueMapper::intValue($rows[0]['CurrentCount'] ?? 0),
        ];
    }

    /**
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function filter(EmployeeListCriteria $criteria): array
    {
        $clauses = [];
        $params = [];

        if ($criteria->status === EmployeeListCriteria::STATUS_CURRENT) {
            $clauses[] = 'e.[IsCurrent] = 1';
        } elseif ($criteria->status === EmployeeListCriteria::STATUS_PAST) {
            $clauses[] = 'e.[IsCurrent] = 0';
        }
        if ($criteria->department !== null) {
            $clauses[] = 'e.[DepartmentRaw] = :department';
            $params[':department'] = $criteria->department;
        }
        if ($criteria->factory !== null) {
            $clauses[] = 'e.[FactoryRaw] = :factory';
            $params[':factory'] = $criteria->factory;
        }
        if ($criteria->manager !== null) {
            $clauses[] = 'LOWER(LTRIM(RTRIM(e.[ManagerLogin]))) = :manager';
            $params[':manager'] = strtolower($criteria->manager);
        }
        if ($criteria->search !== null) {
            // LIKE wildcards in the term are literal.
            $pattern = '%' . strtr($criteria->search, ['[' => '[[]', '%' => '[%]', '_' => '[_]']) . '%';
            $searchable = ['EmployeeCode', 'UserLogin', 'FirstName', 'LastName', 'DisplayName'];
            $likes = [];
            foreach ($searchable as $index => $column) {
                $likes[] = 'e.[' . $column . '] LIKE :search' . $index;
                $params[':search' . $index] = $pattern;
            }
            $clauses[] = '(' . implode(' OR ', $likes) . ')';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @return list<array{value: string, count: int}>
     */
    private function facet(string $column, string $where): array
    {
        $rows = $this->connection->fetchAll(
            "SELECT LTRIM(RTRIM([{$column}])) AS [Value], COUNT(*) AS [Count] FROM [hr].[Employees] {$where}"
            . ($where === '' ? ' WHERE' : ' AND') . " NULLIF(LTRIM(RTRIM([{$column}])), N'') IS NOT NULL"
            . " GROUP BY LTRIM(RTRIM([{$column}])) ORDER BY LTRIM(RTRIM([{$column}]))",
        );

        return array_map(static fn (array $row): array => [
            'value' => AzureSqlValueMapper::stringValue($row['Value'] ?? ''),
            'count' => AzureSqlValueMapper::intValue($row['Count'] ?? 0),
        ], $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function item(array $row): array
    {
        $linkedId = AzureSqlValueMapper::nullableString($row['LinkedUserPublicId'] ?? null);

        return [
            'code' => AzureSqlValueMapper::stringValue($row['EmployeeCode'] ?? ''),
            'login' => AzureSqlValueMapper::nullableString($row['UserLogin'] ?? null),
            'firstName' => AzureSqlValueMapper::nullableString($row['FirstName'] ?? null),
            'lastName' => AzureSqlValueMapper::nullableString($row['LastName'] ?? null),
            'displayName' => AzureSqlValueMapper::nullableString($row['DisplayName'] ?? null),
            'department' => AzureSqlValueMapper::nullableString($row['DepartmentRaw'] ?? null),
            'factory' => AzureSqlValueMapper::nullableString($row['FactoryRaw'] ?? null),
            'workEmail' => AzureSqlValueMapper::nullableString($row['WorkEmail'] ?? null),
            'managerLogin' => AzureSqlValueMapper::nullableString($row['ManagerLogin'] ?? null),
            'managerName' => AzureSqlValueMapper::nullableString($row['ManagerName'] ?? null),
            'dateHired' => AzureSqlValueMapper::nullableString($row['DateHired'] ?? null),
            'isCurrent' => AzureSqlValueMapper::boolValue($row['IsCurrent'] ?? 0),
            'lastSeenInPayroll' => AzureSqlValueMapper::nullableString($row['LastSeenInPayroll'] ?? null),
            'missingFromSourceAt' => AzureSqlValueMapper::nullableString($row['MissingFromSourceAt'] ?? null),
            'linkedUser' => $linkedId === null ? null : [
                'publicId' => strtolower($linkedId),
                'displayName' => AzureSqlValueMapper::nullableString($row['LinkedUserDisplayName'] ?? null),
            ],
        ];
    }

    private static function decimal(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }
}
