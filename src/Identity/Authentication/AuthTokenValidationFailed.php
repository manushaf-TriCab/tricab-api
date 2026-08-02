<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use RuntimeException;

/**
 * Guest (or composite) Bearer authentication failure with stable client error codes.
 */
final class AuthTokenValidationFailed extends RuntimeException
{
    public const AUTH_TOKEN_INVALID = 'AUTH_TOKEN_INVALID';
    public const AUTH_TOKEN_EXPIRED = 'AUTH_TOKEN_EXPIRED';
    public const AUTH_SESSION_REVOKED = 'AUTH_SESSION_REVOKED';
    public const AUTH_SESSION_NOT_FOUND = 'AUTH_SESSION_NOT_FOUND';
    public const AUTH_USER_INACTIVE = 'AUTH_USER_INACTIVE';

    public function __construct(
        string $message,
        public readonly string $errorCode = self::AUTH_TOKEN_INVALID,
        public readonly int $httpStatus = 401,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $reason = 'Token is invalid.'): self
    {
        return new self($reason, self::AUTH_TOKEN_INVALID, 401);
    }

    public static function expired(string $reason = 'Token has expired.'): self
    {
        return new self($reason, self::AUTH_TOKEN_EXPIRED, 401);
    }

    public static function sessionRevoked(): self
    {
        return new self('Guest session has been revoked.', self::AUTH_SESSION_REVOKED, 401);
    }

    public static function sessionNotFound(): self
    {
        return new self('Guest session was not found.', self::AUTH_SESSION_NOT_FOUND, 401);
    }

    public static function userInactive(): self
    {
        return new self('User account is not allowed to authenticate.', self::AUTH_USER_INACTIVE, 401);
    }
}
