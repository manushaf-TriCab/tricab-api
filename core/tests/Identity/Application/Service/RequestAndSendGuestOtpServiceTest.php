<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Application\Service\GuestLoginEligibilityService;
use TriCab\Core\Identity\Application\Service\RequestAndSendGuestOtpService;
use TriCab\Core\Identity\Application\Service\RequestGuestOtpService;
use TriCab\Core\Identity\Domain\Service\PasswordOtpHasher;
use TriCab\Core\Identity\Domain\Service\SecureOtpGenerator;
use TriCab\Core\Tests\Identity\Doubles\FakeGuestLoginEligibilityLookup;
use TriCab\Core\Tests\Identity\Doubles\FakeGuestOtpEmailSender;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Identity\Doubles\InMemoryEmailOtpChallengeRepository;

final class RequestAndSendGuestOtpServiceTest extends TestCase
{
    public function test_sends_otp_and_returns_safe_result_without_plaintext(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $sender = new FakeGuestOtpEmailSender();

        $service = new RequestAndSendGuestOtpService(
            requestGuestOtp: new RequestGuestOtpService(
                challenges: $challenges,
                otpGenerator: new SecureOtpGenerator(),
                otpHasher: new PasswordOtpHasher(),
                eligibility: new GuestLoginEligibilityService(new FakeGuestLoginEligibilityLookup()),
                clock: $clock,
            ),
            emailSender: $sender,
            challenges: $challenges,
        );

        $result = $service->execute('John.Smith@Example.com');

        self::assertSame('j***h@example.com', $result->maskedEmailAddress);
        self::assertNotSame('', $result->challengePublicId);
        self::assertSame('2026-07-31T00:05:00+00:00', $result->expiresAtUtc);
        self::assertObjectNotHasProperty('plaintextOtp', $result);
        self::assertCount(1, $sender->sent);
        self::assertSame('john.smith@example.com', $sender->sent[0]['email']);
        self::assertSame($result->expiresAtUtc, $sender->sent[0]['expiresAtUtc']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $sender->sent[0]['otp']);
        self::assertTrue($challenges->all()[0]->isActive($clock->now()));
    }

    public function test_sender_failure_invalidates_challenge_and_hides_otp_in_exception(): void
    {
        $clock = FixedClock::utc('2026-07-31T00:00:00+00:00');
        $challenges = new InMemoryEmailOtpChallengeRepository($clock);
        $sender = new FakeGuestOtpEmailSender();
        $sender->shouldFail = true;

        $service = new RequestAndSendGuestOtpService(
            requestGuestOtp: new RequestGuestOtpService(
                challenges: $challenges,
                otpGenerator: new SecureOtpGenerator(),
                otpHasher: new PasswordOtpHasher(),
                eligibility: new GuestLoginEligibilityService(new FakeGuestLoginEligibilityLookup()),
                clock: $clock,
            ),
            emailSender: $sender,
            challenges: $challenges,
        );

        try {
            $service->execute('guest@example.com');
            self::fail('Expected GuestOtpEmailDeliveryFailed');
        } catch (GuestOtpEmailDeliveryFailed $exception) {
            self::assertSame('Guest OTP email delivery failed.', $exception->getMessage());
            self::assertDoesNotMatchRegularExpression('/\b\d{6}\b/', $exception->getMessage());
        }

        self::assertCount(1, $challenges->all());
        self::assertTrue($challenges->all()[0]->isInvalidated());
        self::assertFalse($challenges->all()[0]->isActive($clock->now()));
    }
}
