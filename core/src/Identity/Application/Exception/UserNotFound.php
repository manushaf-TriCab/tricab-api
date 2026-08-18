<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class UserNotFound extends RuntimeException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('No user found for email "%s".', $email));
    }

    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('No user found for public id "%s".', $publicId));
    }
}
