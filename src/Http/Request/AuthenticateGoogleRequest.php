<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for POST /api/v1/auth/google.
 */
final readonly class AuthenticateGoogleRequest
{
    private function __construct(
        public string $idToken,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $idToken = $body['idToken'] ?? null;
        if (!is_string($idToken) || trim($idToken) === '') {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_GOOGLE_TOKEN_INVALID',
                'A Google ID token is required.',
            );
        }

        $normalized = trim($idToken);
        if (substr_count($normalized, '.') !== 2) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_GOOGLE_TOKEN_INVALID',
                'A valid Google ID token is required.',
            );
        }

        return new self($normalized);
    }
}
