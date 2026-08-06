<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for POST /api/v1/auth/guest/request-otp.
 */
final readonly class RequestGuestOtpRequest
{
    public const MAX_EMAIL_LENGTH = 320;

    private function __construct(public string $email)
    {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        if (!array_key_exists('email', $body)) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_EMAIL_INVALID',
                'Enter a valid email address.',
            );
        }

        $email = $body['email'];
        if (!is_string($email)) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_EMAIL_INVALID',
                'Enter a valid email address.',
            );
        }

        $trimmed = trim($email);
        if (
            $trimmed === ''
            || strlen($trimmed) > self::MAX_EMAIL_LENGTH
            || filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_EMAIL_INVALID',
                'Enter a valid email address.',
            );
        }

        return new self($trimmed);
    }
}
