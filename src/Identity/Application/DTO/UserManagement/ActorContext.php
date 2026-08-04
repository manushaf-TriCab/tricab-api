<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class ActorContext
{
    public function __construct(
        public int $userId,
        public string $publicId,
        public string $emailAddress,
        public ?string $correlationId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {
    }
}
