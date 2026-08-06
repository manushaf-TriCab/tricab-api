<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

/**
 * Placeholder rate limiter until a distributed implementation is introduced.
 */
final class NoOpGuestAuthRateLimiter implements GuestAuthRateLimiter
{
    public function assertRequestOtpAllowed(?string $email, ?string $clientIp): void
    {
        unset($email, $clientIp);
    }

    public function assertVerifyOtpAllowed(?string $challengePublicId, ?string $clientIp): void
    {
        unset($challengePublicId, $clientIp);
    }
}
