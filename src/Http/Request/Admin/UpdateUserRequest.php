<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for PATCH /api/v1/admin/users/{publicId}.
 */
final readonly class UpdateUserRequest
{
    private function __construct(
        public string $displayName,
        public ?string $emailAddress,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $displayName = $body['displayName'] ?? null;
        if (!is_string($displayName) || trim($displayName) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'displayName is required.');
        }

        $emailAddress = null;
        if (array_key_exists('emailAddress', $body) && $body['emailAddress'] !== null) {
            $rawEmail = $body['emailAddress'];
            if (!is_string($rawEmail) || trim($rawEmail) === '') {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'emailAddress must be a non-empty string.');
            }

            $emailAddress = trim($rawEmail);
        }

        return new self(
            displayName: trim($displayName),
            emailAddress: $emailAddress,
        );
    }
}
