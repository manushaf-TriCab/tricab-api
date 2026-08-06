<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Domain\Exception\LastAdminProtection;
use TriCab\Core\Identity\Domain\Exception\SelfLockoutProtection;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Prevents accidental lockout of the last administrator or self-lockout.
 */
final class AdministratorProtectionPolicy
{
    public function assertStatusChangeAllowed(
        UserPublicId $targetPublicId,
        UserPublicId $actorPublicId,
        UserStatus $newStatus,
        bool $targetHasAdministratorRole,
        int $activeAdministratorCount,
    ): void {
        if ($newStatus->allowsAuthentication()) {
            return;
        }

        if ($targetPublicId->equals($actorPublicId)) {
            throw SelfLockoutProtection::cannotDisableOwnAccount();
        }

        if ($targetHasAdministratorRole && $activeAdministratorCount <= 1) {
            throw LastAdminProtection::cannotDisableFinalAdministrator();
        }
    }

    public function assertAdministratorRoleRemovalAllowed(
        UserPublicId $targetPublicId,
        UserPublicId $actorPublicId,
        RoleCode $roleCode,
        int $activeAdministratorCount,
    ): void {
        if (!ProtectedIdentityCatalog::isAdministratorRole($roleCode)) {
            return;
        }

        if ($targetPublicId->equals($actorPublicId)) {
            throw SelfLockoutProtection::cannotRemoveOwnLastAdministratorRole();
        }

        if ($activeAdministratorCount <= 1) {
            throw LastAdminProtection::cannotRemoveFinalAdministratorRole();
        }
    }
}
