<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class OtpChallengeConsumed extends DomainException
{
    public static function create(): self
    {
        return new self('The email OTP challenge has already been consumed.');
    }
}
