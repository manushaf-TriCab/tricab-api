<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

/**
 * Validated body for PATCH /api/v1/admin/customer-contacts/{contactPublicId}/status.
 */
final readonly class ChangeCustomerContactStatusRequest
{
    private function __construct(
        public bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(isActive: RequestFields::requireBool($body, 'isActive'));
    }
}
