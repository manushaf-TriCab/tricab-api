<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

final class FakeGuestOtpEmailSender implements GuestOtpEmailSender
{
    public bool $shouldFail = false;

    /** @var list<array{email: string, otp: string, expiresAtUtc: string}> */
    public array $sent = [];

    public function sendGuestLoginCode(
        EmailAddress $emailAddress,
        OtpCode $otpCode,
        DateTimeImmutable $expiresAtUtc,
    ): void {
        if ($this->shouldFail) {
            throw GuestOtpEmailDeliveryFailed::create();
        }

        $this->sent[] = [
            'email' => $emailAddress->toString(),
            'otp' => $otpCode->toString(),
            'expiresAtUtc' => $expiresAtUtc->format(DATE_ATOM),
        ];
    }
}
