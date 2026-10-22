<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\ActAs;

use DateTimeImmutable;

/**
 * One administrator switching into one target user for a bounded time.
 */
final readonly class ActAsSession
{
    public const END_REASON_EXIT = 'exit';
    public const END_REASON_EXPIRED = 'expired';
    public const END_REASON_REVOKED = 'revoked';

    public function __construct(
        public string $id,
        public int $adminUserId,
        public int $targetUserId,
        public string $reason,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $endedAt = null,
        public ?string $endReason = null,
    ) {
    }
}
