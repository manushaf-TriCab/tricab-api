<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\Employees;

use TriCab\Api\Hr\Employees\HrEmployeeSource;

/** A stand-in for tc: rows held in memory. */
final class InMemoryHrEmployeeSource implements HrEmployeeSource
{
    /**
     * @param list<array<string, mixed>> $employees
     * @param list<array<string, mixed>> $departments
     */
    public function __construct(public array $employees = [], public array $departments = [])
    {
    }

    public function employees(): iterable
    {
        return $this->employees;
    }

    public function departmentManagers(): array
    {
        return $this->departments;
    }

    public function close(): void
    {
    }
}
