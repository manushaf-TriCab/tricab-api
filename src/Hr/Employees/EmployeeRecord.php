<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

/**
 * One `tc.employee` row as the platform keeps it: the base columns that
 * anyone with Employees access may see, and the restricted ones that only
 * ever go to `[hr].[EmployeeRestrictedDetails]`. The two never share an array.
 */
final readonly class EmployeeRecord
{
    /**
     * @param array<string, string|null> $base column => value for [hr].[Employees], without the flags and the link
     * @param array<string, string|null> $restricted column => value for [hr].[EmployeeRestrictedDetails], without the key
     */
    public function __construct(
        public string $employeeCode,
        public ?string $normalizedUserLogin,
        public ?string $legacyUpdateDate,
        public string $sourceHash,
        public array $base,
        public array $restricted,
    ) {
    }
}
