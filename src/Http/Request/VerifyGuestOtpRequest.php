<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Validated body for POST /api/v1/auth/guest/verify-otp.
 */
final readonly class VerifyGuestOtpRequest
{
    public const MAX_EMAIL_LENGTH = 320;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    private const OTP_PATTERN = '/^\d{6}$/';

    private function __construct(
        public string $challengeId,
        public string $email,
        public string $otp,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $challengeId = $body['challengeId'] ?? null;
        if (!is_string($challengeId) || trim($challengeId) === '') {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_CHALLENGE_INVALID',
                'Enter a valid challenge identifier.',
            );
        }

        $normalizedChallengeId = strtolower(trim($challengeId));
        if (preg_match(self::UUID_PATTERN, $normalizedChallengeId) !== 1) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_CHALLENGE_INVALID',
                'Enter a valid challenge identifier.',
            );
        }

        $email = $body['email'] ?? null;
        if (!is_string($email)) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_EMAIL_INVALID',
                'Enter a valid email address.',
            );
        }

        $trimmedEmail = trim($email);
        if (
            $trimmedEmail === ''
            || strlen($trimmedEmail) > self::MAX_EMAIL_LENGTH
            || filter_var($trimmedEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_EMAIL_INVALID',
                'Enter a valid email address.',
            );
        }

        if (!array_key_exists('otp', $body)) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_OTP_INVALID',
                'Enter the six-digit verification code.',
            );
        }

        $otp = $body['otp'];
        if (!is_string($otp)) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_OTP_INVALID',
                'The verification code must be sent as a string.',
            );
        }

        if (preg_match(self::OTP_PATTERN, $otp) !== 1) {
            throw HttpRequestValidationFailed::unprocessable(
                'AUTH_OTP_INVALID',
                'Enter the six-digit verification code.',
            );
        }

        return new self($normalizedChallengeId, $trimmedEmail, $otp);
    }
}
