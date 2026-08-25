<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class PasskeyAuthFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 401,
    ) {
        parent::__construct($message);
    }

    public static function setupTokenInvalid(): self
    {
        return new self('AUTH_PASSKEY_SETUP_INVALID', 'This passkey setup link is invalid or has expired.', 400);
    }

    public static function setupTokenExpired(): self
    {
        return new self('AUTH_PASSKEY_SETUP_EXPIRED', 'This passkey setup link has expired.', 400);
    }

    public static function setupTokenConsumed(): self
    {
        return new self('AUTH_PASSKEY_SETUP_USED', 'This passkey setup link has already been used.', 400);
    }

    public static function registrationSessionInvalid(): self
    {
        return new self('AUTH_PASSKEY_REGISTRATION_SESSION_INVALID', 'Passkey setup session is invalid or has expired.', 401);
    }

    public static function challengeInvalid(): self
    {
        return new self('AUTH_PASSKEY_CHALLENGE_INVALID', 'Passkey challenge is invalid or has expired.', 401);
    }

    public static function wrongOrigin(): self
    {
        return new self('AUTH_PASSKEY_ORIGIN_INVALID', 'Passkey origin is not allowed.', 401);
    }

    public static function wrongRpId(): self
    {
        return new self('AUTH_PASSKEY_RP_INVALID', 'Passkey relying party is not allowed.', 401);
    }

    public static function userVerificationRequired(): self
    {
        return new self('AUTH_PASSKEY_USER_VERIFICATION_REQUIRED', 'Passkey user verification is required.', 401);
    }

    public static function duplicateCredential(): self
    {
        return new self('AUTH_PASSKEY_CREDENTIAL_EXISTS', 'This passkey is already registered.', 409);
    }

    public static function unknownCredential(): self
    {
        return new self('AUTH_PASSKEY_UNKNOWN', 'Passkey sign-in could not be completed.', 401);
    }

    public static function inactiveCredential(): self
    {
        return new self('AUTH_PASSKEY_REVOKED', 'This passkey is no longer active.', 401);
    }

    public static function signatureInvalid(): self
    {
        return new self('AUTH_PASSKEY_SIGNATURE_INVALID', 'Passkey sign-in could not be completed.', 401);
    }

    public static function clonedAuthenticator(): self
    {
        return new self('AUTH_PASSKEY_COUNTER_INVALID', 'Passkey sign-in could not be completed.', 401);
    }

    public static function rateLimited(): self
    {
        return new self('AUTH_RATE_LIMITED', 'Too many attempts. Please try again later.', 429);
    }

    public static function notEnabled(): self
    {
        return new self('AUTH_PASSKEY_NOT_CONFIGURED', 'Passkey sign-in is not available.', 501);
    }
}
