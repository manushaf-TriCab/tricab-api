<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Email;

use DateTimeImmutable;
use TriCab\Core\Identity\Contract\GuestPasskeyEmailSender;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

final class DevelopmentGuestPasskeyEmailSender implements GuestPasskeyEmailSender
{
    /** @var array{to: string, subject: string, setupUrl: string, expiresAtUtc: string}|null */
    private ?array $lastMessage = null;

    public function sendPasskeySetupLink(
        EmailAddress $emailAddress,
        string $setupUrl,
        DateTimeImmutable $expiresAtUtc,
    ): void {
        $this->lastMessage = [
            'to' => $emailAddress->toString(),
            'subject' => GuestPasskeyEmailTemplate::subject(),
            'setupUrl' => $setupUrl,
            'expiresAtUtc' => $expiresAtUtc->format(DATE_ATOM),
        ];
    }

    /** @return array{to: string, subject: string, setupUrl: string, expiresAtUtc: string}|null */
    public function lastMessage(): ?array
    {
        return $this->lastMessage;
    }
}
