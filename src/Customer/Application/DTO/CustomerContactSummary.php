<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

final readonly class CustomerContactSummary
{
    public function __construct(
        public string $publicId,
        public string $customerPublicId,
        public string $displayName,
        public ?string $email,
        public ?string $position,
        public bool $isPrimary,
        public bool $isActive,
        public string $portalAccessStatus,
        public string $createdAtUtc,
        public string $updatedAtUtc,
    ) {
    }
}
