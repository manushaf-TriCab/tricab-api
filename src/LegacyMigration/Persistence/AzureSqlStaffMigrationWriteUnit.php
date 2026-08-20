<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LegacyMigration\Contract\LegacyStaffUserMappingRepository;
use TriCab\Core\LegacyMigration\Contract\StaffIdentityUserCreator;
use TriCab\Core\LegacyMigration\Contract\StaffMigrationWriteUnit;

final class AzureSqlStaffMigrationWriteUnit implements StaffMigrationWriteUnit
{
    use AzureSqlTransaction;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly StaffIdentityUserCreator $userCreator,
        private readonly LegacyStaffUserMappingRepository $mappings,
    ) {
    }

    public function createStaffUserWithLegacyMapping(
        EmailAddress $emailAddress,
        string $displayName,
        array $roleCodes,
        UserId $actorUserId,
        array $mappingData,
    ): array {
        return $this->runInTransaction(
            $this->connection,
            function () use ($emailAddress, $displayName, $roleCodes, $actorUserId, $mappingData): array {
                $created = $this->userCreator->createStaffUser(
                    $emailAddress,
                    $displayName,
                    $roleCodes,
                    $actorUserId,
                );
                $this->mappings->insertMapping(array_merge($mappingData, [
                    'userId' => $created['userId'],
                ]));

                return $created;
            },
        );
    }
}
