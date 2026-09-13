<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use LogicException;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LegacyMigration\Contract\StaffIdentityUserCreator;

final class DisabledStaffIdentityUserCreator implements StaffIdentityUserCreator
{
    public function createStaffUser(
        EmailAddress $emailAddress,
        string $displayName,
        array $roleCodes,
        UserId $actorUserId,
    ): array {
        unset($emailAddress, $displayName, $roleCodes, $actorUserId);
        throw new LogicException('Staff migration execution requires USER_REPOSITORY=azure-sql.');
    }

    public function emailExists(EmailAddress $emailAddress): bool
    {
        unset($emailAddress);

        return false;
    }
}
