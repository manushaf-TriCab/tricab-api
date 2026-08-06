<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

/**
 * Validated body for PATCH /api/v1/admin/customer-contacts/{contactPublicId}.
 */
final readonly class UpdateCustomerContactRequest
{
    private function __construct(
        public string $displayName,
        public ?string $email,
        public ?string $position,
        public ?string $phone,
        public ?string $mobile,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            displayName: RequestFields::requireString($body, 'displayName'),
            email: RequestFields::stringOrNull($body, 'email'),
            position: RequestFields::stringOrNull($body, 'position'),
            phone: RequestFields::stringOrNull($body, 'phone'),
            mobile: RequestFields::stringOrNull($body, 'mobile'),
        );
    }
}
