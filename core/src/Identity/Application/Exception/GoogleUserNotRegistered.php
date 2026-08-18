<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class GoogleUserNotRegistered extends RuntimeException
{
    public static function create(): self
    {
        return new self('Your Google account is not registered with TriCab.');
    }
}
