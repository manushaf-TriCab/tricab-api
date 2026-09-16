<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http\Auth;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Auth\GuestAuthRateLimited;
use TriCab\Api\Http\Auth\WindowedGuestAuthRateLimiter;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;

final class WindowedGuestAuthRateLimiterTest extends TestCase
{
    public function test_request_otp_limits_by_email_and_ip(): void
    {
        $limiter = new WindowedGuestAuthRateLimiter(
            cache: new ArrayApplicationCache(),
            requestEmailLimit: 3,
            requestIpLimit: 10,
            windowSeconds: 900,
        );

        $limiter->assertRequestOtpAllowed('a@example.com', '1.1.1.1');
        $limiter->assertRequestOtpAllowed('a@example.com', '1.1.1.1');
        $limiter->assertRequestOtpAllowed('a@example.com', '1.1.1.1');

        $this->expectException(GuestAuthRateLimited::class);
        $limiter->assertRequestOtpAllowed('a@example.com', '1.1.1.1');
    }

    public function test_request_otp_ip_limit_is_independent_of_email(): void
    {
        $limiter = new WindowedGuestAuthRateLimiter(
            cache: new ArrayApplicationCache(),
            requestEmailLimit: 100,
            requestIpLimit: 2,
            windowSeconds: 900,
        );

        $limiter->assertRequestOtpAllowed('a@example.com', '9.9.9.9');
        $limiter->assertRequestOtpAllowed('b@example.com', '9.9.9.9');

        $this->expectException(GuestAuthRateLimited::class);
        $limiter->assertRequestOtpAllowed('c@example.com', '9.9.9.9');
    }
}
