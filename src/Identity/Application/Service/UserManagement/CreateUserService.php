<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\Exception\RoleNotFound;
use TriCab\Core\Identity\Application\Exception\UserEmailConflict;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\Exception\InactiveRoleAssignment;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class CreateUserService
{
    public function __construct(
        private readonly UserManagementRepository $users,
        private readonly RoleManagementRepository $roles,
        private readonly UserManagementAuditRecorder $audit,
    ) {
    }

    /**
     * Creates a user without external identity links (Entra / email OTP are linked later).
     *
     * @param list<string> $roleCodes
     */
    public function execute(
        ActorContext $actor,
        string $emailAddress,
        string $displayName,
        string $status = UserStatus::ACTIVE,
        array $roleCodes = [],
    ): UserDetails {
        $email = EmailAddress::fromString($emailAddress);
        $name = UserManagementAuditRecorder::requireDisplayName($displayName);
        $userStatus = UserStatus::fromString($status);

        if ($this->users->emailExists($email)) {
            throw UserEmailConflict::forEmail($email->toString());
        }

        $resolvedRoles = [];
        foreach ($roleCodes as $roleCodeValue) {
            $roleCode = RoleCode::fromString($roleCodeValue);

            if (!$this->roles->roleCodeExists($roleCode)) {
                throw RoleNotFound::forCode($roleCode->toString());
            }

            if (!$this->roles->isRoleActive($roleCode)) {
                throw InactiveRoleAssignment::forRoleCode($roleCode->toString());
            }

            $resolvedRoles[] = $roleCode;
        }

        $created = $this->users->createUser(
            emailAddress: $email,
            displayName: $name,
            status: $userStatus,
            roleCodes: $resolvedRoles,
            actorUserId: $this->audit->actorUserId($actor),
        );

        $targetUserId = $this->users->findUserIdByPublicId(UserPublicId::fromString($created->publicId));

        $this->audit->record(
            actor: $actor,
            eventType: UserManagementEventType::USER_CREATED,
            eventSummary: sprintf('Created user %s', $created->emailAddress),
            targetUserId: $targetUserId?->toInt(),
            newValues: [
                'emailAddress' => $created->emailAddress,
                'displayName' => $created->displayName,
                'status' => $created->status,
                'roles' => array_map(
                    static fn (RoleCode $role): string => $role->toString(),
                    $resolvedRoles,
                ),
            ],
        );

        return $created;
    }
}
