<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for POST /api/v1/admin/users/{publicId}/roles.
 */
final readonly class AssignUserRoleRequest
{
    private function __construct(
        public string $roleCode,
        public ?DateTimeImmutable $expiresAtUtc,
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

        $expiresAtUtc = null;
        if (array_key_exists('expiresAtUtc', $body) && $body['expiresAtUtc'] !== null) {
            $rawExpiry = $body['expiresAtUtc'];
            if (!is_string($rawExpiry) || trim($rawExpiry) === '') {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'expiresAtUtc must be an ISO-8601 date-time string.');
            }

            try {
                $expiresAtUtc = new DateTimeImmutable($rawExpiry, new DateTimeZone('UTC'));
            } catch (\Exception) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'expiresAtUtc must be a valid ISO-8601 date-time string.');
            }
        }

        return new self(
            roleCode: trim($roleCode),
            expiresAtUtc: $expiresAtUtc,
        );
    }
}
