<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\ActiveOtpChallengeNotFound;
use TriCab\Core\Identity\Contract\EmailOtpChallengeRepository;
use TriCab\Core\Identity\Contract\OtpHasher;
use TriCab\Core\Identity\Domain\Entity\EmailOtpChallenge;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Exception\OtpInvalid;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;
use TriCab\Core\Identity\Domain\ValueObject\OtpPurpose;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Verifies a guest email OTP and returns the provisioned platform user.
 * Does not issue JWT or HTTP responses.
 */
final class VerifyGuestOtpService
{
    public function __construct(
        private readonly EmailOtpChallengeRepository $challenges,
        private readonly OtpHasher $otpHasher,
        private readonly GuestUserProvisioningService $provisioning,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function execute(
        string $email,
        string $otp,
        ?string $challengePublicId = null,
        ?OtpPurpose $purpose = null,
    ): User {
        $emailAddress = EmailAddress::fromString($email);
        $otpCode = OtpCode::fromString($otp);
        $purpose ??= OtpPurpose::guestLogin();
        $now = $this->clock->now();

        $challenge = $this->resolveChallenge($emailAddress, $purpose, $challengePublicId);

        if (!$this->otpHasher->verify($otpCode, $challenge->otpHash())) {
            try {
                $challenge->registerFailedAttempt($now);
            } finally {
                $this->challenges->save($challenge);
            }

            // registerFailedAttempt always throws; this satisfies static analysis.
            throw OtpInvalid::create();
        }

        $challenge->consume($now);
        $this->challenges->save($challenge);

        $user = $this->provisioning->ensureGuestUser($emailAddress);
        $user->assertAuthenticationAllowed();

        return $user;
    }

    private function resolveChallenge(
        EmailAddress $emailAddress,
        OtpPurpose $purpose,
        ?string $challengePublicId,
    ): EmailOtpChallenge {
        if ($challengePublicId !== null && trim($challengePublicId) !== '') {
            $publicId = UuidPublicId::fromString($challengePublicId);
            $challenge = $this->challenges->findByPublicId($publicId);
            if (
                $challenge === null
                || !$challenge->emailAddress()->equals($emailAddress)
                || !$challenge->purpose()->equals($purpose)
            ) {
                throw ActiveOtpChallengeNotFound::forChallenge($publicId->toString());
            }

            return $challenge;
        }

        $challenge = $this->challenges->findLatestActiveByEmailAndPurpose($emailAddress, $purpose);
        if ($challenge === null) {
            throw ActiveOtpChallengeNotFound::forEmail($emailAddress->toString());
        }

        return $challenge;
    }
}
