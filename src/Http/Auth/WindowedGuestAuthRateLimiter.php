<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use TriCab\Api\Infrastructure\Cache\ApplicationCache;

/**
 * Windowed OTP rate limits (email + IP), matching README policy:
 * 3 request-otp per email / 15 minutes
 * 10 request-otp per IP / 15 minutes
 * 20 verify-otp per IP / 15 minutes
 * 20 verify-otp per challenge / 15 minutes
 */
final class WindowedGuestAuthRateLimiter implements GuestAuthRateLimiter
{
    public function __construct(
        private readonly ApplicationCache $cache,
        private readonly int $requestEmailLimit = 3,
        private readonly int $requestIpLimit = 10,
        private readonly int $verifyIpLimit = 20,
        private readonly int $verifyChallengeLimit = 20,
        private readonly int $windowSeconds = 900,
    ) {
    }

    public function assertRequestOtpAllowed(?string $email, ?string $clientIp): void
    {
        $normalizedEmail = strtolower(trim((string) $email));
        if ($normalizedEmail !== '') {
            $this->hit('guest-otp-request-email:' . $normalizedEmail, $this->requestEmailLimit);
        }

        $ip = trim((string) $clientIp);
        if ($ip !== '') {
            $this->hit('guest-otp-request-ip:' . $ip, $this->requestIpLimit);
        }
    }

    public function assertVerifyOtpAllowed(?string $challengePublicId, ?string $clientIp): void
    {
        $challenge = strtolower(trim((string) $challengePublicId));
        if ($challenge !== '') {
            $this->hit('guest-otp-verify-challenge:' . $challenge, $this->verifyChallengeLimit);
        }

        $ip = trim((string) $clientIp);
        if ($ip !== '') {
            $this->hit('guest-otp-verify-ip:' . $ip, $this->verifyIpLimit);
        }
    }

    private function hit(string $key, int $limit): void
    {
        $count = $this->cache->get($key);
        $n = is_int($count) ? $count : 0;
        if ($n >= $limit) {
            throw GuestAuthRateLimited::create();
        }
        $this->cache->set($key, $n + 1, $this->windowSeconds);
    }
}
