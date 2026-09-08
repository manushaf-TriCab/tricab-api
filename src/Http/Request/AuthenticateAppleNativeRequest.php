<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for POST /api/v1/auth/apple/native.
 * Additive native Sign in with Apple identity-token contract.
 */
final readonly class AuthenticateAppleNativeRequest
{
    private function __construct(
        public string $identityToken,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $identityToken = $body['identityToken'] ?? null;

        if (!is_string($identityToken) || trim($identityToken) === '') {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_APPLE_IDENTITY_TOKEN_REQUIRED',
                'An Apple identity token is required.',
            );
        }

        return new self(identityToken: trim($identityToken));
    }
}
