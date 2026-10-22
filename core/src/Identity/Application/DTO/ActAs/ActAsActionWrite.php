<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\ActAs;

use DateTimeImmutable;

/**
 * One state-changing request made while switched. Request bodies are never stored.
 */
final readonly class ActAsActionWrite
{
    public function __construct(
        public string $sessionId,
        public int $adminUserId,
        public int $targetUserId,
        public string $method,
        public string $routePattern,
        public string $path,
        public int $statusCode,
        public int $durationMs,
        public ?string $requestId,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
