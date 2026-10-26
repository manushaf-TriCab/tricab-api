<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

/**
 * Who read whose restricted details, when, and through which route. Records
 * identifiers only, never a restricted value.
 */
interface RestrictedReadAudit
{
    public function record(ActorContext $actor, string $employeeCode, string $endpoint): void;
}
