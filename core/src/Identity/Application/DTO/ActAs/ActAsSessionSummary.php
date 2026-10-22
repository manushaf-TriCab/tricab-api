<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\ActAs;

/**
 * Audit-view row: a session with the admin and target resolved to people.
 */
final readonly class ActAsSessionSummary
{
    public function __construct(
        public string $sessionId,
        public string $reason,
        public string $startedAt,
        public string $expiresAt,
        public ?string $endedAt,
        public ?string $endReason,
        public string $adminPublicId,
        public string $adminDisplayName,
        public string $adminEmailAddress,
        public string $targetPublicId,
        public string $targetDisplayName,
        public string $targetEmailAddress,
    ) {
    }
}
