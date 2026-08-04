<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

/**
 * Validated body for PATCH /api/v1/admin/customer-contacts/{contactPublicId}/portal-access.
 * `enabled: true` grants access (EnableCustomerPortalAccessService), `enabled: false`
 * revokes it (DisableCustomerPortalAccessService).
 */
final readonly class ChangePortalAccessRequest
{
    private function __construct(
        public bool $enabled,
        public bool $activateImmediately,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            enabled: RequestFields::requireBool($body, 'enabled'),
            activateImmediately: RequestFields::boolOrDefault($body, 'activateImmediately', false),
        );
    }
}
