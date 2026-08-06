<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;

/**
 * Validated body for POST /api/v1/admin/customers.
 */
final readonly class CreateCustomerRequest
{
    private function __construct(
        public string $customerName,
        public ?string $customerCode,
        public string $status,
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
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            customerName: RequestFields::requireString($body, 'customerName'),
            customerCode: RequestFields::stringOrNull($body, 'customerCode'),
            status: RequestFields::stringOrDefault($body, 'status', CustomerStatus::ACTIVE),
            addressLine1: RequestFields::stringOrNull($body, 'addressLine1'),
            addressLine2: RequestFields::stringOrNull($body, 'addressLine2'),
            suburb: RequestFields::stringOrNull($body, 'suburb'),
            postcode: RequestFields::stringOrNull($body, 'postcode'),
            countryCode: RequestFields::stringOrNull($body, 'countryCode'),
            marketCode: RequestFields::stringOrNull($body, 'marketCode'),
            salesRep: RequestFields::stringOrNull($body, 'salesRep'),
            territory: RequestFields::stringOrNull($body, 'territory'),
            phone: RequestFields::stringOrNull($body, 'phone'),
            legacyCustomerId: RequestFields::intOrNull($body, 'legacyCustomerId'),
        );
    }
}
