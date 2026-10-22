<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionEntry;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionWrite;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSession;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSessionSummary;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

interface ActAsSessionRepository
{
    public function findUserIdByPublicId(UserPublicId $publicId): ?UserId;

    public function create(
        string $sessionId,
        UserId $adminUserId,
        UserId $targetUserId,
        string $reason,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $expiresAt,
        ?string $clientIp,
        ?string $userAgent,
    ): ActAsSession;

    public function findById(string $sessionId): ?ActAsSession;

    /**
     * Ends a session that is still open. Returns false when it was already ended.
     */
    public function end(string $sessionId, string $endReason, DateTimeImmutable $endedAt): bool;

    public function appendAction(ActAsActionWrite $action): void;

    /**
     * Newest first.
     *
     * @return list<ActAsSessionSummary>
     */
    public function listRecent(int $limit): array;

    /**
     * Oldest first.
     *
     * @return list<ActAsActionEntry>
     */
    public function listActions(string $sessionId, int $limit): array;
}
