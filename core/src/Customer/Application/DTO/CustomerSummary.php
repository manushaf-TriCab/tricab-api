<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

final readonly class CustomerSummary
{
    public function __construct(
        public string $publicId,
        public ?string $customerCode,
        public string $customerName,
        public string $status,
        public bool $isActive,
        public ?string $suburb,
        public ?string $postcode,
        public ?string $countryCode,
        public ?string $marketCode,
        public ?string $territoryCode,
        public ?string $phone,
        public int $contactCount,
        public int $portalUserCount,
        public string $createdAtUtc,
        public string $updatedAtUtc,
    ) {
    }
}
