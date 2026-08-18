<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

final readonly class CustomerContactDetails
{
    public function __construct(
        public string $publicId,
        public string $customerPublicId,
        public string $displayName,
        public ?string $email,
        public ?string $normalizedEmail,
        public ?string $position,
        public ?string $phone,
        public ?string $mobile,
        public bool $isPrimary,
        public bool $isActive,
        public string $portalAccessStatus,
        public ?int $legacyContactId,
        public string $createdAtUtc,
        public string $updatedAtUtc,
    ) {
    }
}
