<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\UserManagement;

final readonly class UserCustomerAssociation
{
    public function __construct(
        public string $customerPublicId,
        public string $customerName,
        public string $contactPublicId,
        public string $contactDisplayName,
        public ?string $portalAccessStatus,
        public bool $isPrimary,
        public bool $isActive,
        public string $linkedAtUtc,
    ) {
    }
}
