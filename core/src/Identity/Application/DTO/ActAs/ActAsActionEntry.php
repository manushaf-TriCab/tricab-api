<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\ActAs;

final readonly class ActAsActionEntry
{
    public function __construct(
        public int $id,
        public string $sessionId,
        public string $method,
        public string $routePattern,
        public string $path,
        public int $statusCode,
        public int $durationMs,
        public ?string $requestId,
        public string $occurredAt,
    ) {
    }
}
