<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class TrustedDeviceIneligible extends DomainException
{
    public static function create(): self
    {
        return new self('The user is not eligible for trusted-device login.');
    }

    public static function missingLoginPermission(): self
    {
        return new self('ACCOUNT.LOGIN is required for trusted-device registration.');
    }
}
