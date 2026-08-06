<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

/**
 * Validated body for PATCH /api/v1/admin/customers/{customerPublicId}.
 */
final readonly class UpdateCustomerRequest
{
    private function __construct(
        public string $customerName,
        public ?string $customerCode,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $suburb,
        public ?string $postcode,
        public ?string $countryCode,
        public ?string $marketCode,
        public ?string $salesRep,
        public ?string $territory,
        public ?string $phone,
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
            addressLine1: RequestFields::stringOrNull($body, 'addressLine1'),
            addressLine2: RequestFields::stringOrNull($body, 'addressLine2'),
            suburb: RequestFields::stringOrNull($body, 'suburb'),
            postcode: RequestFields::stringOrNull($body, 'postcode'),
            countryCode: RequestFields::stringOrNull($body, 'countryCode'),
            marketCode: RequestFields::stringOrNull($body, 'marketCode'),
            salesRep: RequestFields::stringOrNull($body, 'salesRep'),
            territory: RequestFields::stringOrNull($body, 'territory'),
            phone: RequestFields::stringOrNull($body, 'phone'),
        );
    }
}
