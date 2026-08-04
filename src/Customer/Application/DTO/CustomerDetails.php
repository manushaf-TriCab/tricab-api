<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

final readonly class CustomerDetails
{
    public function __construct(
        public string $publicId,
        public ?string $customerCode,
        public string $customerName,
        public string $status,
        public bool $isActive,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $suburb,
        public ?string $postcode,
        public ?string $countryCode,
        public ?string $marketCode,
        public ?string $salesRep,
        public ?string $territory,
        public ?string $phone,
        public ?int $legacyCustomerId,
        public string $createdAtUtc,
        public string $updatedAtUtc,
    ) {
    }
}
