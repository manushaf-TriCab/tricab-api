<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

/**
 * Validated body for PATCH /api/v1/admin/customers/{customerPublicId}/status.
 */
final readonly class ChangeCustomerStatusRequest
{
    private function __construct(
        public string $status,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(status: RequestFields::requireString($body, 'status'));
    }
}
