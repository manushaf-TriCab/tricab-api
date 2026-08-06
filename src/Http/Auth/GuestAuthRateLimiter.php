<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

/**
 * Integration point for future guest-auth rate limiting.
 *
 * Intended production limits (not enforced by this no-op implementation):
 * - 3 OTP requests per email per 15 minutes
 * - 10 OTP requests per IP per 15 minutes
 * - 5 verification attempts per challenge (already enforced by EmailOtpChallenge)
 * - optional temporary IP blocking after repeated failures
 *
 * Do not use insecure in-memory limiters in multi-instance production.
 */
interface GuestAuthRateLimiter
{
    public function assertRequestOtpAllowed(?string $email, ?string $clientIp): void;

    public function assertVerifyOtpAllowed(?string $challengePublicId, ?string $clientIp): void;
}
