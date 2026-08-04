<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for PATCH /api/v1/admin/users/{publicId}/status.
 */
final readonly class ChangeUserStatusRequest
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
        $status = $body['status'] ?? null;
        if (!is_string($status) || trim($status) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'status is required.');
        }

        return new self(status: trim($status));
    }
}
