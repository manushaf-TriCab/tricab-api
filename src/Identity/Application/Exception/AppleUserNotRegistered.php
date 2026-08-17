<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class AppleUserNotRegistered extends RuntimeException
{
    public static function create(): self
    {
        return new self('This Apple account is not linked to a registered TriCab user.');
    }
}
