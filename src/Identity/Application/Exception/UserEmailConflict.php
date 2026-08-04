<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class UserEmailConflict extends RuntimeException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('A user with email "%s" already exists.', $email));
    }
}
