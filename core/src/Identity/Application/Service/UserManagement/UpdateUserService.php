<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\Exception\UserEmailConflict;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

final class UpdateUserService
{
    public function __construct(
        private readonly UserManagementRepository $users,
        private readonly UserManagementAuditRecorder $audit,
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $publicId,
        string $displayName,
        ?string $emailAddress = null,
    ): UserDetails {
        $id = UserPublicId::fromString($publicId);
        $existing = $this->users->findDetailsByPublicId($id);

        if ($existing === null) {
            throw UserNotFound::forPublicId($id->toString());
        }

        $name = UserManagementAuditRecorder::requireDisplayName($displayName);
        $email = $emailAddress !== null ? EmailAddress::fromString($emailAddress) : null;

        if ($email !== null && $this->users->emailExists($email, $id)) {
            throw UserEmailConflict::forEmail($email->toString());
        }

        $updated = $this->users->updateUser(
            publicId: $id,
            displayName: $name,
            emailAddress: $email,
            actorUserId: $this->audit->actorUserId($actor),
        );

        $targetUserId = $this->users->findUserIdByPublicId($id);

        $this->audit->record(
            actor: $actor,
            eventType: UserManagementEventType::USER_UPDATED,
            eventSummary: sprintf('Updated user %s', $updated->emailAddress),
            targetUserId: $targetUserId?->toInt(),
            previousValues: [
                'emailAddress' => $existing->emailAddress,
                'displayName' => $existing->displayName,
            ],
            newValues: [
                'emailAddress' => $updated->emailAddress,
                'displayName' => $updated->displayName,
            ],
        );

        return $updated;
    }
}
