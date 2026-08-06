<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

/**
 * Provider-independent port for delivering guest login OTP emails.
 * Implementations live in the API / infrastructure layer.
 */
interface GuestOtpEmailSender
{
    /**
     * @throws GuestOtpEmailDeliveryFailed
     */
    public function sendGuestLoginCode(
        EmailAddress $emailAddress,
        OtpCode $otpCode,
        DateTimeImmutable $expiresAtUtc,
    ): void;
}
