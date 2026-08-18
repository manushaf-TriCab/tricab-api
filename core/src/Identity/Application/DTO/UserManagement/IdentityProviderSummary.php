<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class IdentityProviderSummary
{
    public function __construct(
        public string $providerCode,
        public bool $isLinked,
        public bool $isActive,
        public ?string $lastAuthenticatedAtUtc = null,
        public ?string $maskedSubject = null,
    ) {
    }
}
