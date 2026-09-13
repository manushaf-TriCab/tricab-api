<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use LogicException;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LegacyMigration\Contract\StaffMigrationWriteUnit;

final class DisabledStaffMigrationWriteUnit implements StaffMigrationWriteUnit
{
    public function createStaffUserWithLegacyMapping(
        EmailAddress $emailAddress,
        string $displayName,
        array $roleCodes,
        UserId $actorUserId,
        array $mappingData,
    ): array {
        unset($emailAddress, $displayName, $roleCodes, $actorUserId, $mappingData);
        throw new LogicException('Staff migration execution requires USER_REPOSITORY=azure-sql.');
    }
}
