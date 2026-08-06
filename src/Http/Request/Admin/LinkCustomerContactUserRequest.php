<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

/**
 * Validated body for POST /api/v1/admin/customer-contacts/{contactPublicId}/link-user.
 */
final readonly class LinkCustomerContactUserRequest
{
    private function __construct(
        public string $userPublicId,
        public bool $isPrimary,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            userPublicId: RequestFields::requireString($body, 'userPublicId'),
            isPrimary: RequestFields::boolOrDefault($body, 'isPrimary', false),
        );
    }
}
