<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for PATCH /api/v1/admin/roles/{roleCode}/status.
 */
final readonly class ChangeRoleStatusRequest
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
        $isActive = $body['isActive'] ?? null;
        if (!is_bool($isActive)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'isActive is required and must be a boolean.');
        }

        return new self(isActive: $isActive);
    }
}
