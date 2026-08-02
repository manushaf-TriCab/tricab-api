<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use DateTimeImmutable;
use Throwable;
use TriCab\Core\Identity\Application\DTO\SentGuestOtp;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Contract\EmailOtpChallengeRepository;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Domain\Service\EmailMasker;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;
use TriCab\Core\Identity\Domain\ValueObject\OtpPurpose;

/**
 * Requests a guest OTP challenge and delivers the code by email.
 * Plaintext OTP is never returned to callers.
 */
final class RequestAndSendGuestOtpService
{
    public function __construct(
        private readonly RequestGuestOtpService $requestGuestOtp,
        private readonly GuestOtpEmailSender $emailSender,
        private readonly EmailOtpChallengeRepository $challenges,
    ) {
    }

    public function execute(
        string $email,
        ?string $requestedIpAddress = null,
        ?string $requestedUserAgent = null,
        ?OtpPurpose $purpose = null,
    ): SentGuestOtp {
        $purpose ??= OtpPurpose::guestLogin();
        $requested = $this->requestGuestOtp->execute(
            email: $email,
            requestedIpAddress: $requestedIpAddress,
            requestedUserAgent: $requestedUserAgent,
            purpose: $purpose,
        );

        $emailAddress = EmailAddress::fromString($requested->emailAddress);
        $otpCode = OtpCode::fromString($requested->plaintextOtp);
        $expiresAtUtc = new DateTimeImmutable($requested->expiresAtUtc);

        try {
            $this->emailSender->sendGuestLoginCode($emailAddress, $otpCode, $expiresAtUtc);
        } catch (Throwable $exception) {
            $this->challenges->invalidateActiveChallenges($emailAddress, $purpose);

            if ($exception instanceof GuestOtpEmailDeliveryFailed) {
                throw $exception;
            }

            throw GuestOtpEmailDeliveryFailed::fromThrowable($exception);
        }

        return new SentGuestOtp(
            challengePublicId: $requested->challengePublicId,
            maskedEmailAddress: EmailMasker::mask($emailAddress),
            expiresAtUtc: $requested->expiresAtUtc,
        );
    }
}
