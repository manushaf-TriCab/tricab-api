<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

/**
 * Reads of the `[hr]` mirror. An employee "item" is the base shape the list and
 * the detail share (see {@see EmployeeDirectoryService}); the restricted columns
 * are only ever returned by {@see self::restricted()}, so no other read can leak them.
 *
 * @phpstan-type Item array<string, mixed>
 */
interface EmployeeDirectory
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(EmployeeListCriteria $criteria): array;

    /**
     * Distinct non-blank values with counts, for one status filter.
     *
     * @return array{departments: list<array{value: string, count: int}>, factories: list<array{value: string, count: int}>}
     */
    public function facets(string $status): array;

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array;

    /**
     * The employee a manager login points at, case-insensitively; a current one
     * wins when payroll holds case-variant duplicates. Null when dangling.
     *
     * @return array<string, mixed>|null
     */
    public function findByLogin(string $login): ?array;

    /**
     * Current employees whose ManagerLogin is this login, case-insensitively.
     *
     * @return list<array<string, mixed>>
     */
    public function directReports(string $login): array;

    /**
     * The restricted block, all keys present (null when payroll holds nothing).
     * Null when there is no restricted row at all. Callers must hold
     * HR.EMPLOYEES.RESTRICTED.READ and record the read.
     *
     * @return array<string, mixed>|null
     */
    public function restricted(string $code): ?array;

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function syncRuns(int $limit): array;

    /**
     * @return array{latestPayrollDate: ?string, currentCount: int}
     */
    public function totals(): array;
}
