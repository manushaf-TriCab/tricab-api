<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for POST /api/v1/auth/apple.
 */
final readonly class AuthenticateAppleRequest
{
    private function __construct(
        public string $authorizationCode,
        public string $state,
        public string $nonce,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        if (isset($body['email']) && (!isset($body['authorizationCode']) || !is_string($body['authorizationCode']) || trim((string) $body['authorizationCode']) === '')) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_APPLE_CODE_REQUIRED',
                'An Apple authorization code is required.',
            );
        }

        $authorizationCode = $body['authorizationCode'] ?? null;
        $state = $body['state'] ?? null;
        $nonce = $body['nonce'] ?? null;

        if (!is_string($authorizationCode) || trim($authorizationCode) === '') {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_APPLE_CODE_REQUIRED',
                'An Apple authorization code is required.',
            );
        }

        if (!is_string($state) || trim($state) === '') {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_APPLE_STATE_INVALID',
                'A valid Apple authorization state is required.',
            );
        }

        if (!is_string($nonce) || trim($nonce) === '') {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_APPLE_NONCE_INVALID',
                'A valid Apple authorization nonce is required.',
            );
        }

        return new self(
            authorizationCode: trim($authorizationCode),
            state: trim($state),
            nonce: trim($nonce),
        );
    }
}
