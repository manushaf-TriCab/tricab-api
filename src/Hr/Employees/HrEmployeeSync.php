<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Copies payroll's `tc.employee` (and `dept_manager`) into `[hr]`. Read-only
 * mirror: nothing is ever written back to tc and nothing is ever deleted here.
 *
 * - Rows are upserted by EmployeeCode; a row whose source hash is unchanged is
 *   not rewritten.
 * - An employee is current when their update_date is the newest in the source
 *   (the latest payroll refresh). If that would shrink the current set below
 *   {@see self::SHORT_REFRESH_RATIO} of what it was, the run keeps every
 *   IsCurrent flag as it was, still copies the other fields, and records
 *   'guarded' with a warning: a short export must not turn staff into leavers.
 * - A row that has left the source is flagged (MissingFromSourceAt, once) and
 *   no longer current; it stays.
 * - UserId comes from the {@see UserLoginLinker}, by login only.
 *
 * A dry run reads both sides and writes nothing. An apply is one transaction
 * under an application lock, and records a [hr].[SyncRuns] row. The result
 * holds counts only, never an employee's values.
 */
final class HrEmployeeSync
{
    public const LOCK = 'hr-employee-sync';

    /** A refresh whose current set is under this share of the previous one is held back. */
    public const SHORT_REFRESH_RATIO = 0.8;

    public function __construct(
        private readonly HrEmployeeStore $store,
        private readonly UserLoginLinker $linker,
    ) {
    }

    /** @return array<string, mixed> */
    public function refresh(HrEmployeeSource $source, bool $apply): array
    {
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Read the source before any lock, so a slow tc never holds locks on the platform.
        $records = [];
        $skipped = 0;
        foreach ($source->employees() as $row) {
            $record = EmployeeMapper::map($row);
            if ($record === null) {
                ++$skipped;
                continue;
            }
            $records[$record->employeeCode] = $record;
        }
        if ($records === []) {
            throw new HrEmployeeSyncRefused('tc.employee returned no rows; nothing is changed.');
        }
        $departments = $source->departmentManagers();

        try {
            if (!$apply) {
                return $this->run($records, $departments, $skipped, false, $startedAt);
            }

            return $this->store->transaction(fn (): array => $this->run($records, $departments, $skipped, true, $startedAt));
        } catch (HrEmployeeSyncBusy $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($apply) {
                $this->store->recordFailure($e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * @param array<string, EmployeeRecord> $records
     * @param list<array<string, mixed>> $departments
     *
     * @return array<string, mixed>
     */
    private function run(array $records, array $departments, int $skipped, bool $apply, DateTimeImmutable $startedAt): array
    {
        $existing = $this->store->employees();

        $latest = null;
        foreach ($records as $record) {
            if ($record->legacyUpdateDate !== null && ($latest === null || $record->legacyUpdateDate > $latest)) {
                $latest = $record->legacyUpdateDate;
            }
        }

        $previousCurrent = count(array_filter($existing, static fn (array $e): bool => $e['isCurrent']));
        $newCurrent = 0;
        foreach ($records as $record) {
            if ($latest !== null && $record->legacyUpdateDate === $latest) {
                ++$newCurrent;
            }
        }
        $guarded = $previousCurrent > 0 && $newCurrent < self::SHORT_REFRESH_RATIO * $previousCurrent;
        $warning = $guarded
            ? sprintf(
                'The newest payroll refresh holds %d current employees against %d before (under %d%%); IsCurrent flags were left as they were.',
                $newCurrent,
                $previousCurrent,
                (int) round(self::SHORT_REFRESH_RATIO * 100),
            )
            : null;

        $logins = [];
        foreach ($records as $record) {
            if ($record->normalizedUserLogin !== null) {
                $logins[$record->normalizedUserLogin] = $record->normalizedUserLogin;
            }
        }
        $links = $this->linker->link(array_values($logins));

        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'flagsChanged' => 0, 'missing' => 0];
        $currentCount = 0;
        $unmatched = 0;
        $unmatchedAll = 0;
        foreach ($records as $code => $record) {
            $code = (string) $code; // PHP turns an all-digit array key into an int
            $before = $existing[$code] ?? null;
            $isCurrent = $guarded
                ? ($before['isCurrent'] ?? false)
                : ($latest !== null && $record->legacyUpdateDate === $latest);
            $userId = ($record->normalizedUserLogin === null ? null : ($links[$record->normalizedUserLogin] ?? null)) ?? ($before['userId'] ?? null);

            $currentCount += $isCurrent ? 1 : 0;
            if ($userId === null) {
                ++$unmatchedAll;
                $unmatched += $isCurrent ? 1 : 0;
            }

            if ($before === null) {
                ++$counts['inserted'];
                if ($apply) {
                    $this->store->insertEmployee($record, $isCurrent, $userId);
                }
            } elseif ($before['hash'] !== $record->sourceHash) {
                ++$counts['updated'];
                if ($apply) {
                    $this->store->updateEmployee($record, $isCurrent, $userId);
                }
            } else {
                ++$counts['unchanged'];
                if ($before['isCurrent'] !== $isCurrent || $before['userId'] !== $userId || $before['missing']) {
                    ++$counts['flagsChanged'];
                    if ($apply) {
                        $this->store->updateFlags($code, $isCurrent, $userId);
                    }
                }
            }
        }

        // Never deleted: flagged once, and not current (unless the guard is holding the flags).
        foreach ($existing as $code => $before) {
            $code = (string) $code;
            if (isset($records[$code])) {
                continue;
            }
            $counts['missing'] += $before['missing'] ? 0 : 1;
            $currentCount += $guarded && $before['isCurrent'] ? 1 : 0;
            if (!$before['missing'] || ($before['isCurrent'] && !$guarded)) {
                if ($apply) {
                    $this->store->markMissing($code, !$guarded);
                }
            }
        }

        $managers = $this->mirrorDepartmentManagers($departments, $apply);

        $result = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'status' => $guarded ? 'guarded' : 'succeeded',
            'rowsRead' => count($records),
            'rowsSkipped' => $skipped,
            'rowsInserted' => $counts['inserted'],
            'rowsUpdated' => $counts['updated'],
            'rowsUnchanged' => $counts['unchanged'],
            'rowsMissing' => $counts['missing'],
            'flagsChanged' => $counts['flagsChanged'],
            'previousCurrent' => $previousCurrent,
            'currentCount' => $currentCount,
            'latestPayrollDate' => $latest,
            'unmatchedLogins' => $unmatched,
            'unmatchedLoginsAll' => $unmatchedAll,
            'departmentManagers' => $managers,
            'warning' => $warning,
        ];

        if ($apply) {
            $this->store->recordRun($result + ['startedAt' => $startedAt]);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    private function mirrorDepartmentManagers(array $rows, bool $apply): array
    {
        $existing = $this->store->departmentManagers();
        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach ($rows as $row) {
            $department = EmployeeMapper::text($row['department'] ?? null);
            if ($department === null) {
                continue;
            }
            $manager = EmployeeMapper::text($row['manager'] ?? null);
            $alt = EmployeeMapper::text($row['alt_manager'] ?? null);
            $before = $existing[$department] ?? null;
            if ($before !== null && $before['manager'] === $manager && $before['alt'] === $alt) {
                ++$counts['unchanged'];
                continue;
            }
            ++$counts[$before === null ? 'inserted' : 'updated'];
            if ($apply) {
                $this->store->upsertDepartmentManager($department, $manager, $alt, $before !== null);
            }
        }

        return $counts;
    }
}
