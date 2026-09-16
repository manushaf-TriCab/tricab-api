<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use RuntimeException;

/**
 * Thrown when guest OTP request/verify exceeds configured rate limits.
 * Public response must not reveal account existence.
 */
final class GuestAuthRateLimited extends RuntimeException
{
    public function __construct(
        string $message = 'Too many attempts. Please try again later.',
        public readonly string $errorCode = 'AUTH_RATE_LIMITED',
        public readonly int $httpStatus = 429,
    ) {
        parent::__construct($message);
    }

    public static function create(): self
    {
        return new self();
    }
}
