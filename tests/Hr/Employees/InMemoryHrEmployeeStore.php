<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\Employees;

use TriCab\Api\Hr\Employees\EmployeeRecord;
use TriCab\Api\Hr\Employees\HrEmployeeStore;
use TriCab\Api\Hr\Employees\HrEmployeeSyncBusy;

/** The [hr] tables in memory; `$writes` counts every mutating call. */
final class InMemoryHrEmployeeStore implements HrEmployeeStore
{
    /** @var array<string, array{hash: string, isCurrent: bool, missing: bool, userId: ?int}> */
    public array $employees = [];
    /** @var array<string, array<string, string|null>> */
    public array $base = [];
    /** @var array<string, array<string, string|null>> */
    public array $restricted = [];
    /** @var array<string, array{manager: ?string, alt: ?string}> */
    public array $departments = [];
    /** @var list<array<string, mixed>> */
    public array $runs = [];
    public int $writes = 0;
    public bool $busy = false;
    public int $failures = 0;

    public function employees(): array
    {
        return $this->employees;
    }

    public function departmentManagers(): array
    {
        return $this->departments;
    }

    public function transaction(callable $work): mixed
    {
        if ($this->busy) {
            throw new HrEmployeeSyncBusy('busy');
        }

        return $work();
    }

    public function insertEmployee(EmployeeRecord $record, bool $isCurrent, ?int $userId): void
    {
        ++$this->writes;
        $this->put($record, $isCurrent, $userId);
    }

    public function updateEmployee(EmployeeRecord $record, bool $isCurrent, ?int $userId): void
    {
        ++$this->writes;
        $this->put($record, $isCurrent, $userId);
    }

    public function updateFlags(string $employeeCode, bool $isCurrent, ?int $userId): void
    {
        ++$this->writes;
        $this->employees[$employeeCode] = ['hash' => $this->employees[$employeeCode]['hash'], 'isCurrent' => $isCurrent, 'missing' => false, 'userId' => $userId];
    }

    public function markMissing(string $employeeCode, bool $clearCurrent): void
    {
        ++$this->writes;
        $before = $this->employees[$employeeCode];
        $this->employees[$employeeCode] = ['hash' => $before['hash'], 'isCurrent' => $clearCurrent ? false : $before['isCurrent'], 'missing' => true, 'userId' => $before['userId']];
    }

    public function upsertDepartmentManager(string $department, ?string $manager, ?string $alt, bool $exists): void
    {
        ++$this->writes;
        $this->departments[$department] = ['manager' => $manager, 'alt' => $alt];
    }

    public function recordRun(array $run): void
    {
        $this->runs[] = $run;
    }

    public function recordFailure(string $error): void
    {
        ++$this->failures;
    }

    private function put(EmployeeRecord $record, bool $isCurrent, ?int $userId): void
    {
        $this->employees[$record->employeeCode] = ['hash' => $record->sourceHash, 'isCurrent' => $isCurrent, 'missing' => false, 'userId' => $userId];
        $this->base[$record->employeeCode] = $record->base;
        $this->restricted[$record->employeeCode] = $record->restricted;
    }
}
