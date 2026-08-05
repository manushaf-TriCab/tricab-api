<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use DateInterval;
use TriCab\Core\Identity\Application\DTO\RequestedGuestOtp;
use TriCab\Core\Identity\Contract\EmailOtpChallengeRepository;
use TriCab\Core\Identity\Contract\OtpGenerator;
use TriCab\Core\Identity\Contract\OtpHasher;
use TriCab\Core\Identity\Domain\Entity\EmailOtpChallenge;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpPurpose;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Creates a hashed email OTP challenge and returns the plaintext OTP to the caller.
 * Does not send email. Eligibility is checked before any challenge is created.
 */
final class RequestGuestOtpService
{
    public const DEFAULT_TTL = 'PT5M';

    public function __construct(
        private readonly EmailOtpChallengeRepository $challenges,
        private readonly OtpGenerator $otpGenerator,
        private readonly OtpHasher $otpHasher,
        private readonly GuestLoginEligibilityService $eligibility,
        private readonly UuidV4Generator $uuidGenerator = new UuidV4Generator(),
        private readonly Clock $clock = new SystemClock(),
        private readonly string $ttl = self::DEFAULT_TTL,
        private readonly int $maximumAttempts = EmailOtpChallenge::DEFAULT_MAXIMUM_ATTEMPTS,
    ) {
    }

    public function execute(
        string $email,
        ?string $requestedIpAddress = null,
        ?string $requestedUserAgent = null,
        ?OtpPurpose $purpose = null,
    ): RequestedGuestOtp {
        $emailAddress = EmailAddress::fromString($email);
        $purpose ??= OtpPurpose::guestLogin();
        $now = $this->clock->now();

        // Gate before invalidate/generate/hash/save — ineligible emails must not touch OTP storage.
        $this->eligibility->assertEligible($emailAddress);

        $this->challenges->invalidateActiveChallenges($emailAddress, $purpose);

        $otp = $this->otpGenerator->generate();
        $challenge = EmailOtpChallenge::request(
            publicId: $this->uuidGenerator->generate(),
            emailAddress: $emailAddress,
            otpHash: $this->otpHasher->hash($otp),
            purpose: $purpose,
            createdAtUtc: $now,
            expiresAtUtc: $now->add(new DateInterval($this->ttl)),
            maximumAttempts: $this->maximumAttempts,
            requestedIpAddress: $requestedIpAddress,
            requestedUserAgent: $requestedUserAgent,
        );

        $this->challenges->save($challenge);

        return new RequestedGuestOtp(
            emailAddress: $emailAddress->toString(),
            purpose: $purpose->toString(),
            plaintextOtp: $otp->toString(),
            challengePublicId: $challenge->publicId()->toString(),
            expiresAtUtc: $challenge->expiresAtUtc()->format(DATE_ATOM),
        );
    }
}
