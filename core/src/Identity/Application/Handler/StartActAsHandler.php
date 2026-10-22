<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Handler;

use DateInterval;
use DateTimeZone;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsResolution;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\Exception\ActAsNotAllowed;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Application\Service\UserManagement\UserManagementAuditRecorder;
use TriCab\Core\Identity\Contract\ActAsSessionRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Shared\Time\Clock;

/**
 * Starts a time-boxed switch from a platform administrator into another user.
 * A switch to an administrator is refused so a lower-access administrator can
 * never gain the permissions of a higher-access one.
 */
final class StartActAsHandler
{
    public const SESSION_MINUTES = 30;
    public const MAX_REASON_LENGTH = 500;

    public function __construct(
        private readonly ActAsSessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly UserManagementAuditRecorder $audit,
        private readonly Clock $clock,
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    public function handle(ActorContext $actor, string $targetPublicId, string $reason): ActAsResolution
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw InvalidIdentityValue::for('ActAsReason', 'length must be 1-' . self::MAX_REASON_LENGTH);
        }

        $admin = $this->users->findById(UserId::fromInt($actor->userId));
        if ($admin === null || !$admin->hasRole(ProtectedIdentityCatalog::administrator())) {
            throw ActAsNotAllowed::callerNotAdministrator();
        }

        $publicId = UserPublicId::fromString($targetPublicId);
        $targetId = $this->sessions->findUserIdByPublicId($publicId);
        $target = $targetId === null ? null : $this->users->findById($targetId);
        if ($targetId === null || $target === null) {
            throw UserNotFound::forPublicId($publicId->toString());
        }

        if ($targetId->toInt() === $actor->userId) {
            throw ActAsNotAllowed::targetIsSelf();
        }

        if (!$target->status()->equals(UserStatus::active())) {
            throw ActAsNotAllowed::targetNotActive();
        }

        if ($target->hasRole(ProtectedIdentityCatalog::administrator())) {
            throw ActAsNotAllowed::targetIsAdministrator();
        }

        $now = $this->clock->now();
        $session = $this->sessions->create(
            sessionId: $this->uuids->generate()->toString(),
            adminUserId: UserId::fromInt($actor->userId),
            targetUserId: $targetId,
            reason: $reason,
            startedAt: $now,
            expiresAt: $now->add(new DateInterval('PT' . self::SESSION_MINUTES . 'M')),
            clientIp: $actor->ipAddress,
            userAgent: $actor->userAgent,
        );

        $this->audit->record(
            actor: $actor,
            eventType: UserManagementEventType::ACT_AS_STARTED,
            eventSummary: sprintf('Started acting as %s', $target->emailAddress()->toString()),
            targetUserId: $targetId->toInt(),
            newValues: [
                'sessionId' => $session->id,
                'reason' => $reason,
                'expiresAt' => $session->expiresAt->setTimezone(new DateTimeZone('Australia/Melbourne'))->format(DATE_ATOM),
            ],
        );

        return new ActAsResolution($session, AuthenticatedUser::fromUser($target));
    }
}
