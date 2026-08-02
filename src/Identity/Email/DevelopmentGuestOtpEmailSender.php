<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Email;

use DateTimeImmutable;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Domain\Service\EmailMasker;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

/**
 * Local development sender. Does not call external email providers.
 * OTP is held in memory for tests only; reveal-to-STDERR is explicit opt-in.
 */
final class DevelopmentGuestOtpEmailSender implements GuestOtpEmailSender
{
    /** @var array{to: string, subject: string, expiresAtUtc: string, otpCode: string}|null */
    private ?array $lastMessage = null;

    public function __construct(
        private readonly bool $revealEnabled = false,
        private readonly bool $isProduction = false,
    ) {
        if ($this->isProduction) {
            throw GuestOtpEmailProviderException::configuration(
                'DevelopmentGuestOtpEmailSender cannot be used in production.',
            );
        }
    }

    public function sendGuestLoginCode(
        EmailAddress $emailAddress,
        OtpCode $otpCode,
        DateTimeImmutable $expiresAtUtc,
    ): void {
        $this->lastMessage = [
            'to' => $emailAddress->toString(),
            'subject' => GuestOtpEmailTemplate::subject(),
            'expiresAtUtc' => $expiresAtUtc->format(DATE_ATOM),
            'otpCode' => $otpCode->toString(),
        ];

        if (!$this->revealEnabled) {
            return;
        }

        fwrite(
            STDERR,
            sprintf(
                "[GUEST_OTP_DEV_REVEAL] development-only OTP for %s expires %s: %s\n",
                EmailMasker::mask($emailAddress),
                $expiresAtUtc->format(DATE_ATOM),
                $otpCode->toString(),
            ),
        );
    }

    /**
     * @return array{to: string, subject: string, expiresAtUtc: string, otpCode: string}|null
     */
    public function lastMessage(): ?array
    {
        return $this->lastMessage;
    }
}
