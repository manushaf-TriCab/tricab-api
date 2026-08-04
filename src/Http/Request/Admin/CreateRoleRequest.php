<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for POST /api/v1/admin/roles.
 */
final readonly class CreateRoleRequest
{
    private function __construct(
        public string $roleCode,
        public string $roleName,
        public ?string $description,
        public bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $roleCode = $body['roleCode'] ?? null;
        if (!is_string($roleCode) || trim($roleCode) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'roleCode is required.');
        }

        $roleName = $body['roleName'] ?? null;
        if (!is_string($roleName) || trim($roleName) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'roleName is required.');
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
            roleCode: trim($roleCode),
            roleName: trim($roleName),
            description: $description,
            isActive: $isActive,
        );
    }
}
