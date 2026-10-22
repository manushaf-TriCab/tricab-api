<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionEntry;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionWrite;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSession;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSessionSummary;
use TriCab\Core\Identity\Contract\ActAsSessionRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

/**
 * Development/test ActAsSessionRepository. Reads users from the same
 * InMemoryApiUserRepository that authentication uses, so a user registered in
 * a test is switchable.
 */
final class InMemoryActAsSessionRepository implements ActAsSessionRepository
{
    /** @var array<string, ActAsSession> */
    private array $sessions = [];

    /** @var list<ActAsActionEntry> */
    private array $actions = [];

    public function __construct(
        private readonly InMemoryApiUserRepository $users,
    ) {
    }

    public function findUserIdByPublicId(UserPublicId $publicId): ?UserId
    {
        return $this->users->findByPublicId($publicId)?->id();
    }

    public function create(
        string $sessionId,
        UserId $adminUserId,
        UserId $targetUserId,
        string $reason,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $expiresAt,
        ?string $clientIp,
        ?string $userAgent,
    ): ActAsSession {
        $session = new ActAsSession(
            $sessionId,
            $adminUserId->toInt(),
            $targetUserId->toInt(),
            $reason,
            $startedAt,
            $expiresAt,
        );
        $this->sessions[$sessionId] = $session;

        return $session;
    }

    public function findById(string $sessionId): ?ActAsSession
    {
        return $this->sessions[strtolower($sessionId)] ?? null;
    }

    public function end(string $sessionId, string $endReason, DateTimeImmutable $endedAt): bool
    {
        $session = $this->sessions[$sessionId] ?? null;
        if ($session === null || $session->endedAt !== null) {
            return false;
        }

        $this->sessions[$sessionId] = new ActAsSession(
            $session->id,
            $session->adminUserId,
            $session->targetUserId,
            $session->reason,
            $session->startedAt,
            $session->expiresAt,
            $endedAt,
            $endReason,
        );

        return true;
    }

    public function appendAction(ActAsActionWrite $action): void
    {
        $this->actions[] = new ActAsActionEntry(
            id: count($this->actions) + 1,
            sessionId: $action->sessionId,
            method: $action->method,
            routePattern: $action->routePattern,
            path: $action->path,
            statusCode: $action->statusCode,
            durationMs: $action->durationMs,
            requestId: $action->requestId,
            occurredAt: self::melbourne($action->occurredAt),
        );
    }

    public function listRecent(int $limit): array
    {
        $sessions = array_reverse(array_values($this->sessions));
        $summaries = [];
        foreach (array_slice($sessions, 0, $limit) as $session) {
            $admin = $this->users->findById(UserId::fromInt($session->adminUserId));
            $target = $this->users->findById(UserId::fromInt($session->targetUserId));
            if ($admin === null || $target === null) {
                continue;
            }

            $summaries[] = new ActAsSessionSummary(
                sessionId: $session->id,
                reason: $session->reason,
                startedAt: self::melbourne($session->startedAt),
                expiresAt: self::melbourne($session->expiresAt),
                endedAt: $session->endedAt === null ? null : self::melbourne($session->endedAt),
                endReason: $session->endReason,
                adminPublicId: $admin->publicId()->toString(),
                adminDisplayName: $admin->displayName(),
                adminEmailAddress: $admin->emailAddress()->toString(),
                targetPublicId: $target->publicId()->toString(),
                targetDisplayName: $target->displayName(),
                targetEmailAddress: $target->emailAddress()->toString(),
            );
        }

        return $summaries;
    }

    public function listActions(string $sessionId, int $limit): array
    {
        $matching = array_values(array_filter(
            $this->actions,
            static fn (ActAsActionEntry $entry): bool => $entry->sessionId === $sessionId,
        ));

        return array_slice($matching, 0, $limit);
    }

    private static function melbourne(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('Australia/Melbourne'))->format(DATE_ATOM);
    }
}
