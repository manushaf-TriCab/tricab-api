<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

/**
 * Validated body for POST /api/v1/admin/customers/{customerPublicId}/contacts.
 */
final readonly class CreateCustomerContactRequest
{
    private function __construct(
        public string $displayName,
        public ?string $email,
        public ?string $position,
        public ?string $phone,
        public ?string $mobile,
        public bool $isPrimary,
        public bool $isActive,
        public ?int $legacyContactId,
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
            isPrimary: RequestFields::boolOrDefault($body, 'isPrimary', false),
            isActive: RequestFields::boolOrDefault($body, 'isActive', true),
            legacyContactId: RequestFields::intOrNull($body, 'legacyContactId'),
        );
    }
}
