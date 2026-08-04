<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\Service\AdministratorProtectionPolicy;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class ChangeUserStatusService
{
    public function __construct(
        private readonly UserManagementRepository $users,
        private readonly UserManagementAuditRecorder $audit,
        private readonly AdministratorProtectionPolicy $protection = new AdministratorProtectionPolicy(),
    ) {
    }

    public function execute(ActorContext $actor, string $publicId, string $status): UserDetails
    {
        $id = UserPublicId::fromString($publicId);
        $existing = $this->users->findDetailsByPublicId($id);

        if ($existing === null) {
            throw UserNotFound::forPublicId($id->toString());
        }

        $newStatus = UserStatus::fromString($status);
        $actorPublicId = UserPublicId::fromString($actor->publicId);
        $hasAdmin = $this->users->userHasActiveRole($id, ProtectedIdentityCatalog::administrator());
        $adminCount = $this->users->countActiveUsersWithRole(ProtectedIdentityCatalog::administrator());

        $this->protection->assertStatusChangeAllowed(
            targetPublicId: $id,
            actorPublicId: $actorPublicId,
            newStatus: $newStatus,
            targetHasAdministratorRole: $hasAdmin,
            activeAdministratorCount: $adminCount,
        );

        $updated = $this->users->changeStatus(
            publicId: $id,
            status: $newStatus,
            actorUserId: $this->audit->actorUserId($actor),
        );

        $targetUserId = $this->users->findUserIdByPublicId($id);

        $this->audit->record(
            actor: $actor,
            eventType: UserManagementEventType::USER_STATUS_CHANGED,
            eventSummary: sprintf('Changed status for %s to %s', $updated->emailAddress, $updated->status),
            targetUserId: $targetUserId?->toInt(),
            previousValues: ['status' => $existing->status],
            newValues: ['status' => $updated->status],
        );

        return $updated;
    }
}
