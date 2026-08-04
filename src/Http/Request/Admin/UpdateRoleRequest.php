<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for PATCH /api/v1/admin/roles/{roleCode}.
 */
final readonly class UpdateRoleRequest
{
    private function __construct(
        public string $roleName,
        public ?string $description,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
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

        return new self(
            roleName: trim($roleName),
            description: $description,
        );
    }
}
