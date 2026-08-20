<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\LegacyMigration\Contract\StaffIdentityUserCreator;

final class AzureSqlStaffIdentityUserCreator implements StaffIdentityUserCreator
{
    public function __construct(
        private readonly UserManagementRepository $users,
        private readonly SqlConnection $connection,
    ) {
    }

    public function createStaffUser(
        EmailAddress $emailAddress,
        string $displayName,
        array $roleCodes,
        UserId $actorUserId,
    ): array {
        $resolvedRoles = array_map(
            static fn (string $code) => \TriCab\Core\Identity\Domain\ValueObject\RoleCode::fromString($code),
            $roleCodes,
        );

        $details = $this->users->createUser(
            $emailAddress,
            $displayName,
            UserStatus::pending(),
            $resolvedRoles,
            $actorUserId,
        );

        $rows = $this->connection->fetchAll(
            'SELECT [UserId] FROM [identity].[Users] WHERE [PublicId] = :publicId',
            [':publicId' => $details->publicId],
        );
        if ($rows === []) {
            throw new \RuntimeException('Created user could not be resolved by PublicId.');
        }

        return [
            'userId' => AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? 0),
            'publicId' => $details->publicId,
            'emailAddress' => $details->emailAddress,
            'displayName' => $details->displayName,
            'roleCodes' => $roleCodes,
        ];
    }

    public function emailExists(EmailAddress $emailAddress): bool
    {
        return $this->users->emailExists($emailAddress);
    }
}
