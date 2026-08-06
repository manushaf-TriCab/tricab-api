<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\Entity;

use DateInterval;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Entity\EmailOtpChallenge;
use TriCab\Core\Identity\Domain\Exception\OtpAttemptsExceeded;
use TriCab\Core\Identity\Domain\Exception\OtpChallengeConsumed;
use TriCab\Core\Identity\Domain\Exception\OtpChallengeExpired;
use TriCab\Core\Identity\Domain\Exception\OtpChallengeInvalidated;
use TriCab\Core\Identity\Domain\Exception\OtpInvalid;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpPurpose;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

final class EmailOtpChallengeTest extends TestCase
{
    public function test_active_until_expiry_and_then_expired(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenge = $this->challenge($clock);

        self::assertTrue($challenge->isActive($clock->now()));
        self::assertFalse($challenge->isExpired($clock->now()));

        $clock->advance('+11 minutes');
        self::assertTrue($challenge->isExpired($clock->now()));
        self::assertFalse($challenge->isActive($clock->now()));
    }

    public function test_consume_marks_challenge_consumed(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenge = $this->challenge($clock);

        $challenge->consume($clock->now());

        self::assertTrue($challenge->isConsumed());
        self::assertFalse($challenge->isActive($clock->now()));

        $this->expectException(OtpChallengeConsumed::class);
        $challenge->consume($clock->now());
    }

    public function test_invalidate_prevents_further_attempts(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenge = $this->challenge($clock);

        $challenge->invalidate($clock->now());

        self::assertTrue($challenge->isInvalidated());
        $this->expectException(OtpChallengeInvalidated::class);
        $challenge->incrementAttempt($clock->now());
    }

    public function test_failed_attempts_increment_and_eventually_exceed(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenge = $this->challenge($clock, maximumAttempts: 3);

        for ($i = 0; $i < 2; ++$i) {
            try {
                $challenge->registerFailedAttempt($clock->now());
                self::fail('Expected OtpInvalid');
            } catch (OtpInvalid) {
                self::assertSame($i + 1, $challenge->attemptCount());
            }
        }

        $this->expectException(OtpAttemptsExceeded::class);
        $challenge->registerFailedAttempt($clock->now());
    }

    public function test_expired_challenge_cannot_be_consumed(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenge = $this->challenge($clock);
        $clock->advance('+11 minutes');

        $this->expectException(OtpChallengeExpired::class);
        $challenge->consume($clock->now());
    }

    private function challenge(FixedClock $clock, int $maximumAttempts = 5): EmailOtpChallenge
    {
        $now = $clock->now();

        return EmailOtpChallenge::request(
            publicId: (new UuidV4Generator())->generate(),
            emailAddress: EmailAddress::fromString('guest@example.com'),
            otpHash: 'hash',
            purpose: OtpPurpose::guestLogin(),
            createdAtUtc: $now,
            expiresAtUtc: $now->add(new DateInterval('PT10M')),
            maximumAttempts: $maximumAttempts,
        );
    }
}
