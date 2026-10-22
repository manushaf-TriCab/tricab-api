<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Handler;

use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSession;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\Exception\ActAsSessionNotFound;
use TriCab\Core\Identity\Application\Service\UserManagement\UserManagementAuditRecorder;
use TriCab\Core\Identity\Contract\ActAsSessionRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;
use TriCab\Core\Shared\Time\Clock;

/**
 * Ends the callers own switch. Another administrator session is reported as
 * not found, so session ids cannot be probed.
 */
final class EndActAsHandler
{
    public function __construct(
        private readonly ActAsSessionRepository $sessions,
        private readonly UserManagementAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    public function handle(ActorContext $actor, string $sessionId): ActAsSession
    {
        $session = $this->sessions->findById($sessionId);
        if ($session === null || $session->adminUserId !== $actor->userId) {
            throw ActAsSessionNotFound::forId($sessionId);
        }

        // Ending twice is not an error, but it is only audited once.
        if ($this->sessions->end($session->id, ActAsSession::END_REASON_EXIT, $this->clock->now())) {
            $this->audit->record(
                actor: $actor,
                eventType: UserManagementEventType::ACT_AS_ENDED,
                eventSummary: 'Stopped acting as another user',
                targetUserId: $session->targetUserId,
                newValues: ['sessionId' => $session->id, 'endReason' => ActAsSession::END_REASON_EXIT],
            );
        }

        return $this->sessions->findById($session->id) ?? $session;
    }
}
