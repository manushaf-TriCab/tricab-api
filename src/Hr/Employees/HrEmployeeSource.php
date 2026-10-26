<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

/** The payroll copy in the old app's database, `tc.employee` and `tc.dept_manager`. */
interface HrEmployeeSource
{
    /**
     * Every employee row, ordered by employee_code and read in chunks so the
     * table never has to fit in memory.
     *
     * @return iterable<array<string, mixed>>
     */
    public function employees(): iterable;

    /** @return list<array<string, mixed>> department, manager, alt_manager */
    public function departmentManagers(): array;

    public function close(): void;
}
