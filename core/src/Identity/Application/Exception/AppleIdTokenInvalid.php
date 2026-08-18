<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class AppleIdTokenInvalid extends RuntimeException
{
    public const REASON_GENERIC = 'invalid';
    public const REASON_EMAIL_NOT_VERIFIED = 'email_not_verified';
    public const REASON_MISSING_EMAIL = 'missing_email';
    public const REASON_NONCE_MISMATCH = 'nonce_mismatch';
    public const REASON_WRONG_AUDIENCE = 'wrong_audience';
    public const REASON_WRONG_ISSUER = 'wrong_issuer';
    public const REASON_EXPIRED = 'expired';
    public const REASON_NOT_CONFIGURED = 'not_configured';

    private function __construct(
        string $message,
        public readonly string $reason = self::REASON_GENERIC,
    ) {
        parent::__construct($message);
    }

    public static function create(
        string $message = 'Apple ID token validation failed.',
        string $reason = self::REASON_GENERIC,
    ): self {
        return new self($message, $reason);
    }

    public static function emailNotVerified(): self
    {
        return new self('Apple account email is not verified.', self::REASON_EMAIL_NOT_VERIFIED);
    }

    public static function missingEmail(): self
    {
        return new self('Apple ID token is missing a verified email.', self::REASON_MISSING_EMAIL);
    }

    public static function nonceMismatch(): self
    {
        return new self('Apple ID token nonce does not match the expected value.', self::REASON_NONCE_MISMATCH);
    }

    public static function wrongAudience(): self
    {
        return new self('Apple ID token audience is invalid.', self::REASON_WRONG_AUDIENCE);
    }

    public static function wrongIssuer(): self
    {
        return new self('Apple ID token issuer is invalid.', self::REASON_WRONG_ISSUER);
    }

    public static function expired(): self
    {
        return new self('Apple ID token has expired.', self::REASON_EXPIRED);
    }

    public static function notConfigured(): self
    {
        return new self('Apple Sign-In is not configured.', self::REASON_NOT_CONFIGURED);
    }
}
