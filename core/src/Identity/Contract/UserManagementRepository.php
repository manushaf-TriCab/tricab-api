<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListResult;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementSummaryCounts;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

interface UserManagementRepository
{
    public function listUsers(UserListCriteria $criteria): UserListResult;

    public function findDetailsByPublicId(UserPublicId $publicId): ?UserDetails;

    public function findUserIdByPublicId(UserPublicId $publicId): ?UserId;

    public function emailExists(EmailAddress $emailAddress, ?UserPublicId $exceptPublicId = null): bool;

    /**
     * Creates a user without external identity links.
     *
     * @param list<RoleCode> $roleCodes
     */
    public function createUser(
        EmailAddress $emailAddress,
        string $displayName,
        UserStatus $status,
        array $roleCodes,
        UserId $actorUserId,
    ): UserDetails;

    public function updateUser(
        UserPublicId $publicId,
        string $displayName,
        ?EmailAddress $emailAddress,
        UserId $actorUserId,
    ): UserDetails;

    public function changeStatus(
        UserPublicId $publicId,
        UserStatus $status,
        UserId $actorUserId,
    ): UserDetails;

    public function assignRole(
        UserPublicId $publicId,
        RoleCode $roleCode,
        ?DateTimeImmutable $expiresAtUtc,
        UserId $actorUserId,
    ): void;

    /**
     * Removes (deactivates) a role assignment. Returns false when no assignment existed.
     */
    public function removeRole(UserPublicId $publicId, RoleCode $roleCode): bool;

    public function userHasActiveRole(UserPublicId $publicId, RoleCode $roleCode): bool;

    public function countActiveUsersWithRole(RoleCode $roleCode): int;

    public function summarizeCounts(): UserManagementSummaryCounts;
}
