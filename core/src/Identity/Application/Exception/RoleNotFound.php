<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class RoleNotFound extends RuntimeException
{
    public static function forCode(string $roleCode): self
    {
        return new self(sprintf('No role found for code "%s".', $roleCode));
    }
}
