<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for POST /api/v1/admin/permissions.
 */
final readonly class CreatePermissionRequest
{
    private function __construct(
        public string $permissionCode,
        public string $permissionName,
        public string $moduleCode,
        public ?string $description,
        public bool $isActive,
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

        $permissionName = $body['permissionName'] ?? null;
        if (!is_string($permissionName) || trim($permissionName) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'permissionName is required.');
        }

        $moduleCode = $body['moduleCode'] ?? null;
        if (!is_string($moduleCode) || trim($moduleCode) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'moduleCode is required.');
        }

        $description = null;
        if (array_key_exists('description', $body) && $body['description'] !== null) {
            $rawDescription = $body['description'];
            if (!is_string($rawDescription)) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'description must be a string.');
            }

            $description = $rawDescription;
        }

        $isActive = true;
        if (array_key_exists('isActive', $body) && $body['isActive'] !== null) {
            $rawIsActive = $body['isActive'];
            if (!is_bool($rawIsActive)) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'isActive must be a boolean.');
            }

            $isActive = $rawIsActive;
        }

        return new self(
            permissionCode: trim($permissionCode),
            permissionName: trim($permissionName),
            moduleCode: trim($moduleCode),
            description: $description,
            isActive: $isActive,
        );
    }
}
