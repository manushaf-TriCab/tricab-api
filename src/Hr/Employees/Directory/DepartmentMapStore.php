<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

/**
 * `[hr].[DepartmentMap]`: HR's clean names for the raw department and factory
 * values payroll holds (plan HR-EMPLOYEES-PLAN, 4.1). Raw values are matched
 * trimmed; a raw value with no row is simply unmapped and shows as itself.
 */
interface DepartmentMapStore
{
    public const KIND_DEPARTMENT = 'department';
    public const KIND_FACTORY = 'factory';
    public const KINDS = [self::KIND_DEPARTMENT, self::KIND_FACTORY];

    /**
     * Every raw value seen in the mirror (blanks excluded) plus any mapped value
     * no longer seen, so nothing mapped is hidden and nothing unmapped is missed.
     *
     * @return list<array{kind: string, raw: string, cleanName: ?string, costCentre: ?string, mapped: bool, employees: int, currentEmployees: int, updatedAt: ?string, updatedBy: ?string}>
     */
    public function entries(): array;

    /**
     * Sets the mapping of one raw value, or removes it when both fields are null.
     * The change and its audit row are written in one transaction.
     *
     * @return array{kind: string, raw: string, cleanName: ?string, costCentre: ?string, mapped: bool, changed: bool}
     *
     * @throws DepartmentMapRefused when the raw value is in neither the data nor the map
     */
    public function save(ActorContext $actor, string $kind, string $raw, ?string $cleanName, ?string $costCentre): array;
}
