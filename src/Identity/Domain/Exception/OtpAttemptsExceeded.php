<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class OtpAttemptsExceeded extends DomainException
{
    public static function create(): self
    {
        return new self('The maximum number of OTP verification attempts has been exceeded.');
    }
}
