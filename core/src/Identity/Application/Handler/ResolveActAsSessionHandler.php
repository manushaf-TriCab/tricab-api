<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Handler;

use TriCab\Core\Identity\Application\DTO\ActAs\ActAsResolution;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSession;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\Exception\ActAsSessionInvalid;
use TriCab\Core\Identity\Contract\ActAsSessionRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Shared\Time\Clock;

/**
 * Turns an X-Act-As-Session id into the target user, or refuses. Everything is
 * re-checked on every request: the session may have ended or expired, and the
 * target may have been disabled or made an administrator since it began.
 */
final class ResolveActAsSessionHandler
{
    public function __construct(
        private readonly ActAsSessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws ActAsSessionInvalid
     */
    public function handle(AuthenticatedUser $caller, string $sessionId): ActAsResolution
    {
        if (!in_array(ProtectedIdentityCatalog::ROLE_ADMINISTRATOR, $caller->roles, true)) {
            throw ActAsSessionInvalid::because('caller is not an administrator');
        }

        $session = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionId) === 1
            ? $this->sessions->findById($sessionId)
            : null;
        if ($session === null) {
            throw ActAsSessionInvalid::because('session not found');
        }

        if ($session->adminUserId !== $caller->userId) {
            throw ActAsSessionInvalid::because('session belongs to another administrator');
        }

        if ($session->endedAt !== null) {
            throw ActAsSessionInvalid::because('session has ended');
        }

        $now = $this->clock->now();
        if ($session->expiresAt <= $now) {
            $this->sessions->end($session->id, ActAsSession::END_REASON_EXPIRED, $now);

            throw ActAsSessionInvalid::because('session has expired');
        }

        $target = $this->users->findById(UserId::fromInt($session->targetUserId));
        if ($target === null || !$target->status()->equals(UserStatus::active())) {
            throw ActAsSessionInvalid::because('target is not active');
        }

        if ($target->hasRole(ProtectedIdentityCatalog::administrator())) {
            throw ActAsSessionInvalid::because('target is an administrator');
        }

        return new ActAsResolution($session, AuthenticatedUser::fromUser($target));
    }
}
