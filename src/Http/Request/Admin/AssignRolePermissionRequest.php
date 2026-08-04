<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for POST /api/v1/admin/roles/{roleCode}/permissions.
 */
final readonly class AssignRolePermissionRequest
{
    private function __construct(
        public string $permissionCode,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $permissionCode = $body['permissionCode'] ?? null;
        if (!is_string($permissionCode) || trim($permissionCode) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'permissionCode is required.');
        }

        return new self(permissionCode: trim($permissionCode));
    }
}
