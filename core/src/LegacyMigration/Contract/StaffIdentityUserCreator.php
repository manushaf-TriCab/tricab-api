<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Creates staff identity users in NEW Azure SQL only. Never touches legacy systems.
 *
 * @return array{userId: int, publicId: string, emailAddress: string, displayName: string, roleCodes: list<string>}
 */
interface StaffIdentityUserCreator
{
    /**
     * @param list<string> $roleCodes
     * @return array{userId: int, publicId: string, emailAddress: string, displayName: string, roleCodes: list<string>}
     */
    public function createStaffUser(
        EmailAddress $emailAddress,
        string $displayName,
        array $roleCodes,
        UserId $actorUserId,
    ): array;

    public function emailExists(EmailAddress $emailAddress): bool;
}
