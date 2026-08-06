<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

final readonly class CustomerContactUserLinkDetails
{
    public function __construct(
        public string $contactPublicId,
        public string $customerPublicId,
        public string $userPublicId,
        public bool $isPrimary,
        public bool $isActive,
        public string $linkedAtUtc,
        public ?string $unlinkedAtUtc = null,
    ) {
    }
}
