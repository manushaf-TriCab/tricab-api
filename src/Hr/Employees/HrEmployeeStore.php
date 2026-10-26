<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

/** The platform's side: `[hr].[Employees]` and its neighbours. */
interface HrEmployeeStore
{
    /** @return array<string, array{hash: string, isCurrent: bool, missing: bool, userId: ?int}> by EmployeeCode */
    public function employees(): array;

    /** @return array<string, array{manager: ?string, alt: ?string}> by department */
    public function departmentManagers(): array;

    /**
     * Run `$work` in one transaction under the sync's application lock.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws HrEmployeeSyncBusy when another sync holds the lock
     */
    public function transaction(callable $work): mixed;

    public function insertEmployee(EmployeeRecord $record, bool $isCurrent, ?int $userId): void;

    public function updateEmployee(EmployeeRecord $record, bool $isCurrent, ?int $userId): void;

    /** A row whose source did not change, but whose current flag, link or missing mark did. */
    public function updateFlags(string $employeeCode, bool $isCurrent, ?int $userId): void;

    public function markMissing(string $employeeCode, bool $clearCurrent): void;

    public function upsertDepartmentManager(string $department, ?string $manager, ?string $alt, bool $exists): void;

    /** @param array<string, mixed> $run */
    public function recordRun(array $run): void;

    /** Best effort, outside the failed transaction. */
    public function recordFailure(string $error): void;
}
