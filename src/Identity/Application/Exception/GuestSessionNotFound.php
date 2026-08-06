<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class GuestSessionNotFound extends RuntimeException
{
    public static function create(): self
    {
        return new self('Guest session was not found.');
    }
}
