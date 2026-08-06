<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for PATCH /api/v1/admin/permissions/{permissionCode}.
 */
final readonly class UpdatePermissionRequest
{
    private function __construct(
        public string $permissionName,
        public ?string $description,
        public ?bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $permissionName = $body['permissionName'] ?? null;
        if (!is_string($permissionName) || trim($permissionName) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'permissionName is required.');
        }

        $description = null;
        if (array_key_exists('description', $body) && $body['description'] !== null) {
            $rawDescription = $body['description'];
            if (!is_string($rawDescription)) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'description must be a string.');
            }

            $description = $rawDescription;
        }

        $isActive = null;
        if (array_key_exists('isActive', $body) && $body['isActive'] !== null) {
            $rawIsActive = $body['isActive'];
            if (!is_bool($rawIsActive)) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'isActive must be a boolean.');
            }

            $isActive = $rawIsActive;
        }

        return new self(
            permissionName: trim($permissionName),
            description: $description,
            isActive: $isActive,
        );
    }
}
