<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class UserNotRegistered extends RuntimeException
{
    public static function create(): self
    {
        return new self('No registered TriCab user matches the verified provider identity.');
    }
}
