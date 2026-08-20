<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Atomically creates identity user + legacy mapping in NEW Azure SQL.
 */
interface StaffMigrationWriteUnit
{
    /**
     * @param list<string> $roleCodes
     * @param array{
     *   legacyWordPressUserId: int,
     *   legacyLogin: string,
     *   legacyEmailAddress: string,
     *   normalizedEmailAddress: string,
     *   batchPublicId: string,
     *   sourceFingerprint: string,
     *   migrationStatusCode: string
     * } $mappingData
     * @return array{userId: int, publicId: string, emailAddress: string, displayName: string, roleCodes: list<string>}
     */
    public function createStaffUserWithLegacyMapping(
        EmailAddress $emailAddress,
        string $displayName,
        array $roleCodes,
        UserId $actorUserId,
        array $mappingData,
    ): array;
}
