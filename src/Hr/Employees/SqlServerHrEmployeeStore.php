<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** The `[hr]` tables on the platform's Azure SQL database. */
final class SqlServerHrEmployeeStore implements HrEmployeeStore
{
    private const BASE = [
        'UserLogin', 'FirstName', 'LastName', 'DisplayName', 'DepartmentRaw', 'FactoryRaw', 'WorkEmail',
        'ManagerLogin', 'DateHired', 'LegacyUpdateDate',
    ];
    private const RESTRICTED = [
        'PersonalEmail', 'Mobile', 'Street', 'Suburb', 'State', 'Postcode', 'DateOfBirth',
        'AnnualLeaveRemaining', 'LongServiceLeaveRemaining', 'SickLeaveRemaining', 'LeaveAsOf',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function employees(): array
    {
        $found = [];
        $rows = $this->pdo->query('SELECT [EmployeeCode], [SourceHash], [IsCurrent], [MissingFromSourceAt], [UserId] FROM [hr].[Employees]');
        while ($rows !== false && ($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $userId = EmployeeMapper::text($row['UserId'] ?? null);
            $found[(string) EmployeeMapper::text($row['EmployeeCode'] ?? null)] = [
                'hash' => trim((string) EmployeeMapper::text($row['SourceHash'] ?? null)),
                'isCurrent' => (int) EmployeeMapper::text($row['IsCurrent'] ?? null) === 1,
                'missing' => EmployeeMapper::text($row['MissingFromSourceAt'] ?? null) !== null,
                'userId' => $userId === null ? null : (int) $userId,
            ];
        }

        return $found;
    }

    public function departmentManagers(): array
    {
        $found = [];
        $rows = $this->pdo->query('SELECT [Department], [ManagerLogin], [AltManagerLogin] FROM [hr].[DepartmentManagers]');
        while ($rows !== false && ($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $found[(string) EmployeeMapper::text($row['Department'] ?? null)] = [
                'manager' => EmployeeMapper::text($row['ManagerLogin'] ?? null),
                'alt' => EmployeeMapper::text($row['AltManagerLogin'] ?? null),
            ];
        }

        return $found;
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $this->lock();
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function insertEmployee(EmployeeRecord $record, bool $isCurrent, ?int $userId): void
    {
        $columns = ['EmployeeCode', 'NormalizedUserLogin', ...self::BASE, 'IsCurrent', 'UserId', 'SourceHash'];
        $values = [$record->employeeCode, $record->normalizedUserLogin, ...$this->baseValues($record), $isCurrent ? 1 : 0, $userId, $record->sourceHash];
        $this->pdo->prepare(
            'INSERT INTO [hr].[Employees] (' . self::list($columns) . ', [FirstSeenAt], [LastSyncedAt], [CreatedAt], [UpdatedAt], [MissingFromSourceAt])
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ', SYSUTCDATETIME(), SYSUTCDATETIME(), SYSUTCDATETIME(), SYSUTCDATETIME(), NULL)',
        )->execute($values);
        $this->writeRestricted($record, false);
    }

    public function updateEmployee(EmployeeRecord $record, bool $isCurrent, ?int $userId): void
    {
        $columns = ['NormalizedUserLogin', ...self::BASE, 'IsCurrent', 'UserId', 'SourceHash'];
        $this->pdo->prepare(
            'UPDATE [hr].[Employees] SET ' . implode(', ', array_map(static fn (string $c): string => "[{$c}] = ?", $columns))
            . ', [LastSyncedAt] = SYSUTCDATETIME(), [UpdatedAt] = SYSUTCDATETIME(), [MissingFromSourceAt] = NULL WHERE [EmployeeCode] = ?',
        )->execute([$record->normalizedUserLogin, ...$this->baseValues($record), $isCurrent ? 1 : 0, $userId, $record->sourceHash, $record->employeeCode]);
        $this->writeRestricted($record, true);
    }

    public function updateFlags(string $employeeCode, bool $isCurrent, ?int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE [hr].[Employees] SET [IsCurrent] = ?, [UserId] = ?, [MissingFromSourceAt] = NULL, [LastSyncedAt] = SYSUTCDATETIME(), [UpdatedAt] = SYSUTCDATETIME() WHERE [EmployeeCode] = ?',
        )->execute([$isCurrent ? 1 : 0, $userId, $employeeCode]);
    }

    public function markMissing(string $employeeCode, bool $clearCurrent): void
    {
        $this->pdo->prepare(
            'UPDATE [hr].[Employees] SET [MissingFromSourceAt] = COALESCE([MissingFromSourceAt], SYSUTCDATETIME()), [IsCurrent] = CASE WHEN ? = 1 THEN 0 ELSE [IsCurrent] END, [UpdatedAt] = SYSUTCDATETIME() WHERE [EmployeeCode] = ?',
        )->execute([$clearCurrent ? 1 : 0, $employeeCode]);
    }

    public function upsertDepartmentManager(string $department, ?string $manager, ?string $alt, bool $exists): void
    {
        if ($exists) {
            $this->pdo->prepare('UPDATE [hr].[DepartmentManagers] SET [ManagerLogin] = ?, [AltManagerLogin] = ?, [LastSyncedAt] = SYSUTCDATETIME() WHERE [Department] = ?')
                ->execute([$manager, $alt, $department]);

            return;
        }
        $this->pdo->prepare('INSERT INTO [hr].[DepartmentManagers] ([Department], [ManagerLogin], [AltManagerLogin], [LastSyncedAt]) VALUES (?, ?, ?, SYSUTCDATETIME())')
            ->execute([$department, $manager, $alt]);
    }

    public function recordRun(array $run): void
    {
        $started = $run['startedAt'] ?? null;
        $this->pdo->prepare(
            'INSERT INTO [hr].[SyncRuns] ([StartedAt], [FinishedAt], [Mode], [Status], [RowsRead], [RowsInserted], [RowsUpdated], [RowsUnchanged], [RowsMissing], [CurrentCount], [LatestPayrollDate], [UnmatchedLogins], [Warning], [Error])
             VALUES (?, SYSUTCDATETIME(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)',
        )->execute([
            $started instanceof \DateTimeImmutable ? $started->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v') : null,
            $run['mode'] ?? 'apply',
            $run['status'] ?? 'succeeded',
            $run['rowsRead'] ?? 0,
            $run['rowsInserted'] ?? 0,
            $run['rowsUpdated'] ?? 0,
            $run['rowsUnchanged'] ?? 0,
            $run['rowsMissing'] ?? 0,
            $run['currentCount'] ?? 0,
            $run['latestPayrollDate'] ?? null,
            $run['unmatchedLogins'] ?? 0,
            $run['warning'] ?? null,
        ]);
    }

    public function recordFailure(string $error): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO [hr].[SyncRuns] ([StartedAt], [FinishedAt], [Mode], [Status], [RowsRead], [RowsInserted], [RowsUpdated], [RowsUnchanged], [RowsMissing], [Error])
                 VALUES (SYSUTCDATETIME(), SYSUTCDATETIME(), N'apply', N'failed', 0, 0, 0, 0, 0, ?)",
            )->execute([mb_substr($error, 0, 2000)]);
        } catch (Throwable) {
            // Recording the failure must not hide it.
        }
    }

    /** @return list<string|null> */
    private function baseValues(EmployeeRecord $record): array
    {
        return array_map(static fn (string $c): ?string => $record->base[$c] ?? null, self::BASE);
    }

    private function writeRestricted(EmployeeRecord $record, bool $exists): void
    {
        $values = array_map(static fn (string $c): ?string => $record->restricted[$c] ?? null, self::RESTRICTED);
        if ($exists) {
            $update = $this->pdo->prepare(
                'UPDATE [hr].[EmployeeRestrictedDetails] SET ' . implode(', ', array_map(static fn (string $c): string => "[{$c}] = ?", self::RESTRICTED))
                . ', [UpdatedAt] = SYSUTCDATETIME() WHERE [EmployeeCode] = ?',
            );
            $update->execute([...$values, $record->employeeCode]);
            if ($update->rowCount() > 0) {
                return;
            }
        }
        $this->pdo->prepare(
            'INSERT INTO [hr].[EmployeeRestrictedDetails] ([EmployeeCode], ' . self::list(self::RESTRICTED) . ', [UpdatedAt])
             VALUES (?, ' . implode(', ', array_fill(0, count(self::RESTRICTED), '?')) . ', SYSUTCDATETIME())',
        )->execute([$record->employeeCode, ...$values]);
    }

    private function lock(): void
    {
        // Direct, not prepared: sp_prepare does not take a batch that declares a variable.
        $statement = $this->pdo->query(
            "SET NOCOUNT ON; DECLARE @r INT; EXEC @r = sp_getapplock @Resource = N'" . HrEmployeeSync::LOCK . "', @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 0; SELECT @r AS [r];",
        );
        if ($statement === false) {
            throw new RuntimeException('Could not ask for the sync lock.');
        }
        $result = null;
        do {
            try {
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && array_key_exists('r', $row)) {
                    $result = (int) EmployeeMapper::text($row['r']);
                }
            } catch (\PDOException) {
                // a rowset with no columns
            }
        } while ($result === null && $statement->nextRowset());
        if ($result === null || $result < 0) {
            throw new HrEmployeeSyncBusy('Another sync holds the lock.');
        }
    }

    /** @param list<string> $columns */
    private static function list(array $columns): string
    {
        return '[' . implode('], [', $columns) . ']';
    }
}
