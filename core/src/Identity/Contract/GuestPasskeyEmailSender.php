<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

interface GuestPasskeyEmailSender
{
    public function sendPasskeySetupLink(
        EmailAddress $emailAddress,
        string $setupUrl,
        DateTimeImmutable $expiresAtUtc,
    ): void;
}
