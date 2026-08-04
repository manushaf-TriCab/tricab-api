<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

/**
 * Validated body for POST /api/v1/admin/customer-contacts/{contactPublicId}/portal-user.
 */
final readonly class CreateCustomerPortalUserRequest
{
    private function __construct(
        public string $roleCode,
        public bool $activateImmediately,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            roleCode: RequestFields::requireString($body, 'roleCode'),
            activateImmediately: RequestFields::boolOrDefault($body, 'activateImmediately', true),
        );
    }
}
