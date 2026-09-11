<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class TrustedDeviceExpired extends DomainException
{
    public static function create(): self
    {
        return new self('The trusted device has expired.');
    }
}
